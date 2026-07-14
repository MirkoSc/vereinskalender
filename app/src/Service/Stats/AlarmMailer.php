<?php

declare(strict_types=1);

namespace App\Service\Stats;

use App\Repository\SettingRepository;

/**
 * Alert mail to the admin via PHP mail() on import failures and failed
 * update steps (CLAUDE.md section 6). Throttled: max 1 mail per topic and
 * day, mail address is a setting (empty = off).
 */
final readonly class AlarmMailer
{
    public function __construct(
        private SettingRepository $settings,
        private \Closure $mailer, // fn(string $to, string $subject, string $body): bool
    ) {
    }

    public static function withPhpMail(SettingRepository $settings): self
    {
        return new self(
            $settings,
            static fn(string $to, string $subject, string $body): bool => mail(
                $to,
                '=?UTF-8?B?' . base64_encode($subject) . '?=',
                $body,
                "Content-Type: text/plain; charset=utf-8\r\n",
            ),
        );
    }

    public function alert(string $thema, string $subject, string $body): void
    {
        $to = trim($this->settings->get('alarm_email', ''));
        if ($to === '') {
            return;
        }

        $heute = new \DateTimeImmutable()->format('Y-m-d');
        $throttleKey = 'alarm_gesendet_' . preg_replace('/[^a-z0-9_]/', '_', strtolower($thema));
        if ($this->settings->get($throttleKey, '') === $heute) {
            return;
        }

        if (($this->mailer)($to, '[Vereinskalender] ' . $subject, $body)) {
            $this->settings->set($throttleKey, $heute);
        }
    }
}
