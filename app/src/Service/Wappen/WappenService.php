<?php

declare(strict_types=1);

namespace App\Service\Wappen;

/**
 * Vereinswappen (issue #28): the uploaded crest lives in shared/var/wappen/
 * (survives release updates, never in the event log - binary data has no
 * place in the event store, CLAUDE.md section 3). Derived sizes are
 * rendered once via GD at upload time, not on every request.
 *
 * "original.png" is the untouched upload; every other file is a derived
 * square (favicon/apple-touch-icon/PWA icons/header logo). Filenames are
 * fixed - the uploaded filename is never trusted or stored.
 */
final class WappenService
{
    /**
     * name => [size in px, opaque background, maskable safe-zone padding %].
     * Apple/PWA icons need an opaque background (transparent renders as
     * black on iOS); the 192/512 PWA icons additionally keep a safe-zone
     * margin so OS icon masks never clip the crest.
     */
    private const array DERIVATIVES = [
        'favicon-16.png' => [16, false, 0],
        'favicon-32.png' => [32, false, 0],
        'apple-touch-icon.png' => [180, true, 0],
        'icon-192.png' => [192, true, 10],
        'icon-512.png' => [512, true, 10],
        'logo.png' => [256, false, 0],
    ];

    private const int MAX_BYTES = 3 * 1024 * 1024;
    private const int MIN_DIMENSION = 32;
    private const int MAX_DIMENSION = 4000;

    public function __construct(private readonly string $wappenDir)
    {
    }

    public function exists(): bool
    {
        return is_file($this->originalPath());
    }

    /**
     * Cache-busting value for icon/manifest URLs: changes whenever the
     * crest file changes, without needing a DB round-trip to render it.
     */
    public function version(): string
    {
        $mtime = $this->exists() ? filemtime($this->originalPath()) : false;

        return $mtime !== false ? (string) $mtime : '0';
    }

    public function iconPath(string $name): ?string
    {
        if (!isset(self::DERIVATIVES[$name])) {
            return null;
        }
        $path = $this->wappenDir . '/' . $name;

        return is_file($path) ? $path : null;
    }

    /**
     * @return list<string> validation errors; empty list = success
     */
    public function upload(string $tmpPath, int $sizeBytes): array
    {
        if ($sizeBytes <= 0 || $sizeBytes > self::MAX_BYTES) {
            return ['Die Datei ist leer oder größer als 3 MB.'];
        }

        $info = @getimagesize($tmpPath);
        if ($info === false || $info[2] !== \IMAGETYPE_PNG) {
            return ['Bitte eine PNG-Datei hochladen.'];
        }

        [$width, $height] = $info;
        if ($width < self::MIN_DIMENSION || $height < self::MIN_DIMENSION) {
            return [sprintf('Das Bild ist zu klein (mindestens %dx%d Pixel).', self::MIN_DIMENSION, self::MIN_DIMENSION)];
        }
        if ($width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION) {
            return [sprintf('Das Bild ist zu groß (maximal %dx%d Pixel).', self::MAX_DIMENSION, self::MAX_DIMENSION)];
        }

        $source = @imagecreatefrompng($tmpPath);
        if ($source === false) {
            return ['Die Datei konnte nicht als PNG gelesen werden.'];
        }

        if (!is_dir($this->wappenDir) && !mkdir($this->wappenDir, 0775, true) && !is_dir($this->wappenDir)) {
            throw new \RuntimeException('Wappen-Verzeichnis nicht beschreibbar: ' . $this->wappenDir);
        }

        imagepng($source, $this->originalPath());

        foreach (self::DERIVATIVES as $filename => [$size, $opaque, $paddingPercent]) {
            $canvas = $this->renderSquare($source, $size, $opaque, $paddingPercent);
            imagepng($canvas, $this->wappenDir . '/' . $filename);
        }

        return [];
    }

    /**
     * Extracts wappen/*.png entries from a backup ZIP (issue #28: the
     * crest travels inside the backup ZIP; restore just unpacks the
     * files, the setting rows for the "hochgeladen am" hint come back
     * through the normal dump.sql restore).
     */
    public function restoreFromZip(\ZipArchive $zip): void
    {
        if (!is_dir($this->wappenDir) && !mkdir($this->wappenDir, 0775, true) && !is_dir($this->wappenDir)) {
            throw new \RuntimeException('Wappen-Verzeichnis nicht beschreibbar: ' . $this->wappenDir);
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false || !str_starts_with($name, 'wappen/') || str_ends_with($name, '/')) {
                continue;
            }
            $contents = $zip->getFromName($name);
            if ($contents !== false) {
                file_put_contents($this->wappenDir . '/' . basename($name), $contents);
            }
        }
    }

    /**
     * @return list<string> absolute paths of every derived file, for the
     *                       backup ZIP
     */
    public function filesForBackup(): array
    {
        if (!$this->exists()) {
            return [];
        }

        $files = [$this->originalPath()];
        foreach (array_keys(self::DERIVATIVES) as $filename) {
            $path = $this->wappenDir . '/' . $filename;
            if (is_file($path)) {
                $files[] = $path;
            }
        }

        return $files;
    }

    private function originalPath(): string
    {
        return $this->wappenDir . '/original.png';
    }

    private function renderSquare(\GdImage $source, int $size, bool $opaque, int $paddingPercent): \GdImage
    {
        $canvas = imagecreatetruecolor($size, $size);
        imagealphablending($canvas, true);
        imagesavealpha($canvas, true);

        if ($opaque) {
            $background = imagecolorallocate($canvas, 255, 255, 255);
        } else {
            $background = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        }
        imagefilledrectangle($canvas, 0, 0, $size, $size, $background);

        $padding = (int) round($size * $paddingPercent / 100);
        $target = $size - 2 * $padding;
        imagecopyresampled($canvas, $source, $padding, $padding, 0, 0, $target, $target, imagesx($source), imagesy($source));

        return $canvas;
    }
}
