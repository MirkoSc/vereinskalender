<?php

declare(strict_types=1);

namespace App\Admin;

use App\Domain\Palette;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Session;
use App\Repository\SettingRepository;
use App\View\View;

final class SettingsController extends AdminController
{
    public function __construct(
        View $view,
        Session $session,
        private readonly SettingRepository $settings,
    ) {
        parent::__construct($view, $session);
    }

    public function form(Request $request): ResponseInterface
    {
        return $this->render('admin/einstellungen', [
            'title' => 'Einstellungen',
            'values' => [
                'vereinsname' => $this->settings->get('vereinsname', 'Vereinskalender'),
                'nutzungszeiten_von' => $this->settings->get('nutzungszeiten_von', '08:00'),
                'nutzungszeiten_bis' => $this->settings->get('nutzungszeiten_bis', '22:00'),
                'auswaerts_farbe' => $this->settings->get('auswaerts_farbe', '#57606a'),
                'alarm_email' => $this->settings->get('alarm_email', ''),
                'ip_aufbewahrung_tage' => $this->settings->get('ip_aufbewahrung_tage', '90'),
            ],
        ]);
    }

    public function save(Request $request): ResponseInterface
    {
        $fehler = [];

        $vereinsname = trim((string) ($request->post['vereinsname'] ?? ''));
        if ($vereinsname === '' || mb_strlen($vereinsname) > 100) {
            $fehler[] = 'Vereinsname ist erforderlich (max. 100 Zeichen).';
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
            $this->settings->set('vereinsname', $vereinsname);
            $this->settings->set('nutzungszeiten_von', $von);
            $this->settings->set('nutzungszeiten_bis', $bis);
            $this->settings->set('auswaerts_farbe', $farbe);
            $this->settings->set('alarm_email', $email);
            $this->settings->set('ip_aufbewahrung_tage', (string) $tage);
            $this->session->flash('Einstellungen gespeichert.');
        }

        return Response::redirect('/admin/einstellungen');
    }
}
