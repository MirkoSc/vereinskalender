<?php

declare(strict_types=1);

namespace App\Admin;

use App\Domain\Palette;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Session;
use App\Repository\SettingRepository;
use App\Service\Wappen\WappenService;
use App\View\View;

final class SettingsController extends AdminController
{
    public function __construct(
        View $view,
        Session $session,
        private readonly SettingRepository $settings,
        private readonly WappenService $wappen,
    ) {
        parent::__construct($view, $session);
    }

    public function form(Request $request): ResponseInterface
    {
        return $this->render('admin/einstellungen', [
            'title' => 'Einstellungen',
            'values' => [
                'app_name' => $this->settings->get('app_name', 'Vereinskalender'),
                'app_name_kurz' => $this->settings->get('app_name_kurz', ''),
                'nutzungszeiten_von' => $this->settings->get('nutzungszeiten_von', '08:00'),
                'nutzungszeiten_bis' => $this->settings->get('nutzungszeiten_bis', '22:00'),
                'auswaerts_farbe' => $this->settings->get('auswaerts_farbe', '#57606a'),
                'spielfrei_begriffe' => $this->settings->get('spielfrei_begriffe', 'Spielfrei'),
                'spielfrei_farbe' => $this->settings->get('spielfrei_farbe', '#775c3c'),
                'alarm_email' => $this->settings->get('alarm_email', ''),
                'ip_aufbewahrung_tage' => $this->settings->get('ip_aufbewahrung_tage', '90'),
            ],
            'wappenVorhanden' => $this->wappen->exists(),
            'wappenVersion' => $this->wappen->version(),
            'wappenHochgeladenAm' => $this->settings->get('wappen_hochgeladen_am', ''),
        ]);
    }

    public function uploadWappen(Request $request): ResponseInterface
    {
        $upload = $_FILES['wappen'] ?? null;
        if (!is_array($upload) || ($upload['error'] ?? \UPLOAD_ERR_NO_FILE) !== \UPLOAD_ERR_OK) {
            $this->session->flash('Bitte eine PNG-Datei auswählen.');

            return Response::redirect('/admin/einstellungen');
        }

        $fehler = $this->wappen->upload((string) $upload['tmp_name'], (int) $upload['size']);
        if ($fehler !== []) {
            $this->session->flash(implode(' ', $fehler));

            return Response::redirect('/admin/einstellungen');
        }

        $this->settings->set('wappen_hochgeladen_am', new \DateTimeImmutable()->format('Y-m-d H:i:s'));
        $this->session->flash('Wappen hochgeladen. Bereits installierte PWAs übernehmen es erst bei einer Neuinstallation.');

        return Response::redirect('/admin/einstellungen');
    }

    public function save(Request $request): ResponseInterface
    {
        $fehler = [];

        $appName = trim((string) ($request->post['app_name'] ?? ''));
        if ($appName === '' || mb_strlen($appName) > 100) {
            $fehler[] = 'App-Name ist erforderlich (max. 100 Zeichen).';
        }

        $appNameKurz = trim((string) ($request->post['app_name_kurz'] ?? ''));
        if (mb_strlen($appNameKurz) > 30) {
            $fehler[] = 'App-Name (kurz): max. 30 Zeichen.';
        }

        $von = trim((string) ($request->post['nutzungszeiten_von'] ?? ''));
        $bis = trim((string) ($request->post['nutzungszeiten_bis'] ?? ''));
        if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $von) !== 1
            || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $bis) !== 1 || $von >= $bis) {
            $fehler[] = 'Nutzungszeiten: gültige Zeiten angeben (von vor bis).';
        }

        $farbe = trim((string) ($request->post['auswaerts_farbe'] ?? ''));
        if (!Palette::isValid($farbe)) {
            $fehler[] = 'Auswärts-Farbe: bitte aus der Palette wählen.';
        }

        // leer ist gültig - schaltet die Spielfrei-Erkennung im nächsten
        // Importlauf einfach ab (Issue #65), kein Pflichtfeld.
        $spielfreiBegriffe = trim((string) ($request->post['spielfrei_begriffe'] ?? ''));
        if (mb_strlen($spielfreiBegriffe) > 255) {
            $fehler[] = 'Spielfrei-Begriffe: max. 255 Zeichen.';
        }

        $spielfreiFarbe = trim((string) ($request->post['spielfrei_farbe'] ?? ''));
        if (!Palette::isValid($spielfreiFarbe)) {
            $fehler[] = 'Spielfrei-Farbe: bitte aus der Palette wählen.';
        }

        $email = trim((string) ($request->post['alarm_email'] ?? ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $fehler[] = 'Alarm-E-Mail ist ungültig.';
        }

        $tage = (int) ($request->post['ip_aufbewahrung_tage'] ?? 90);
        if ($tage < 1 || $tage > 365) {
            $fehler[] = 'IP-Aufbewahrung: 1–365 Tage.';
        }

        if ($fehler !== []) {
            $this->session->flash(implode(' ', $fehler));
        } else {
            $this->settings->set('app_name', $appName);
            $this->settings->set('app_name_kurz', $appNameKurz);
            $this->settings->set('nutzungszeiten_von', $von);
            $this->settings->set('nutzungszeiten_bis', $bis);
            $this->settings->set('auswaerts_farbe', $farbe);
            $this->settings->set('spielfrei_begriffe', $spielfreiBegriffe);
            $this->settings->set('spielfrei_farbe', $spielfreiFarbe);
            $this->settings->set('alarm_email', $email);
            $this->settings->set('ip_aufbewahrung_tage', (string) $tage);
            $this->session->flash('Einstellungen gespeichert.');
        }

        return Response::redirect('/admin/einstellungen');
    }
}
