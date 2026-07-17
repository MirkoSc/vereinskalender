<?php

declare(strict_types=1);

namespace App\Tests\Service\Wappen;

use App\Service\Wappen\WappenService;
use PHPUnit\Framework\TestCase;

final class WappenServiceTest extends TestCase
{
    private string $wappenDir;

    protected function setUp(): void
    {
        $this->wappenDir = sys_get_temp_dir() . '/vk_wappen_' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->wappenDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->wappenDir)) {
            rmdir($this->wappenDir);
        }
    }

    private function service(): WappenService
    {
        return new WappenService($this->wappenDir);
    }

    private function samplePng(int $width = 512, int $height = 512): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 34, 139, 34));
        $path = sys_get_temp_dir() . '/vk_wappen_src_' . uniqid('', true) . '.png';
        imagepng($image, $path);

        return $path;
    }

    public function testUploadDerivesAllSizes(): void
    {
        $service = $this->service();
        self::assertFalse($service->exists());

        $source = $this->samplePng();
        $fehler = $service->upload($source, (int) filesize($source));
        unlink($source);

        self::assertSame([], $fehler);
        self::assertTrue($service->exists());

        $expected = [
            'favicon-16.png' => 16,
            'favicon-32.png' => 32,
            'apple-touch-icon.png' => 180,
            'icon-192.png' => 192,
            'icon-512.png' => 512,
            'logo.png' => 256,
        ];
        foreach ($expected as $name => $size) {
            $path = $service->iconPath($name);
            self::assertNotNull($path, $name . ' was derived');
            $info = getimagesize($path);
            self::assertSame($size, $info[0], $name . ' width');
            self::assertSame($size, $info[1], $name . ' height');
        }
    }

    public function testVersionChangesAfterReupload(): void
    {
        $service = $this->service();

        $first = $this->samplePng();
        $service->upload($first, (int) filesize($first));
        unlink($first);
        $versionBefore = $service->version();

        // filemtime has 1-second resolution; force a visible change
        touch($this->wappenDir . '/original.png', time() + 5);

        self::assertNotSame('0', $versionBefore);
        self::assertNotSame($versionBefore, $service->version());
    }

    public function testUploadRejectsNonPngContent(): void
    {
        $path = sys_get_temp_dir() . '/vk_wappen_notpng_' . uniqid('', true) . '.png';
        file_put_contents($path, 'not actually a png');

        $fehler = $this->service()->upload($path, (int) filesize($path));
        unlink($path);

        self::assertNotSame([], $fehler);
        self::assertFalse($this->service()->exists());
    }

    public function testUploadRejectsOversizedFile(): void
    {
        $source = $this->samplePng();
        $fehler = $this->service()->upload($source, 4 * 1024 * 1024);
        unlink($source);

        self::assertNotSame([], $fehler);
    }

    public function testUploadRejectsTooSmallImage(): void
    {
        $source = $this->samplePng(8, 8);
        $fehler = $this->service()->upload($source, (int) filesize($source));
        unlink($source);

        self::assertNotSame([], $fehler);
    }

    public function testIconPathRejectsUnknownName(): void
    {
        $service = $this->service();
        $source = $this->samplePng();
        $service->upload($source, (int) filesize($source));
        unlink($source);

        self::assertNull($service->iconPath('../../config.php'));
        self::assertNull($service->iconPath('original.png'));
    }

    public function testFilesForBackupEmptyWithoutUpload(): void
    {
        self::assertSame([], $this->service()->filesForBackup());
    }

    public function testFilesForBackupListsOriginalAndDerivatives(): void
    {
        $service = $this->service();
        $source = $this->samplePng();
        $service->upload($source, (int) filesize($source));
        unlink($source);

        $files = $service->filesForBackup();
        self::assertCount(7, $files, 'original + 6 derivatives');
        foreach ($files as $file) {
            self::assertFileExists($file);
        }
    }

    public function testRestoreFromZipUnpacksWappenEntries(): void
    {
        $service = $this->service();
        $source = $this->samplePng();
        $service->upload($source, (int) filesize($source));
        unlink($source);

        $zipPath = sys_get_temp_dir() . '/vk_wappen_backup_' . uniqid('', true) . '.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);
        foreach ($service->filesForBackup() as $file) {
            $zip->addFile($file, 'wappen/' . basename($file));
        }
        $zip->addFromString('dump.sql', '-- irrelevant');
        $zip->close();

        // restore into a fresh directory, like a fresh install would
        $restoreDir = sys_get_temp_dir() . '/vk_wappen_restore_' . uniqid('', true);
        $restored = new WappenService($restoreDir);

        $readZip = new \ZipArchive();
        $readZip->open($zipPath);
        $restored->restoreFromZip($readZip);
        $readZip->close();
        unlink($zipPath);

        self::assertTrue($restored->exists());
        self::assertNotNull($restored->iconPath('icon-512.png'));

        foreach (glob($restoreDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($restoreDir);
    }
}
