-- Milestone 6: push, usage stats, rate limit, editable legal pages.
-- All of these are TECHNICAL tables, not projections (CLAUDE.md section 4/9).

CREATE TABLE push_subscription (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    endpoint VARCHAR(500) NOT NULL,
    p256dh VARCHAR(255) NOT NULL,
    auth VARCHAR(255) NOT NULL,
    praeferenzen JSON NOT NULL,
    erstellt_am DATETIME NOT NULL,
    UNIQUE KEY uq_push_endpoint (endpoint)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notification_queue (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    typ VARCHAR(32) NOT NULL, -- 'platzsperrung' | 'spielaenderung'
    payload JSON NOT NULL,
    ausgeloest_von_event_id BIGINT UNSIGNED NULL,
    erstellt_am DATETIME NOT NULL,
    gesendet_am DATETIME NULL,
    INDEX idx_queue_pending (gesendet_am)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- aggregated counters instead of an access log: no IPs, no user agents,
-- no cookies (CLAUDE.md section 6)
CREATE TABLE usage_stat (
    datum DATE NOT NULL,
    metrik VARCHAR(64) NOT NULL,
    dimension VARCHAR(100) NULL,
    anzahl INT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY uq_usage (datum, metrik, dimension)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE rate_limit (
    ip VARCHAR(45) NOT NULL PRIMARY KEY,
    fenster_beginn DATETIME NOT NULL,
    anzahl INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- admin-editable content pages (Markdown), maintained without a release
CREATE TABLE page (
    `key` VARCHAR(32) NOT NULL PRIMARY KEY,
    titel VARCHAR(100) NOT NULL,
    inhalt MEDIUMTEXT NOT NULL,
    aktualisiert_am DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO page (`key`, titel, inhalt, aktualisiert_am) VALUES
('impressum', 'Impressum', '# Impressum\n\n*Bitte im Admin-Bereich ausfüllen: Vereinsname, Anschrift, Vertretungsberechtigte, Kontakt.*', NULL),
('datenschutz', 'Datenschutzerklärung', '# Datenschutzerklärung\n\n*Bitte im Admin-Bereich vervollständigen und rechtlich prüfen lassen.*\n\n## Hosting\n\nDiese Website wird bei einem Shared-Hosting-Anbieter gehostet. Beim Aufruf der Seiten verarbeitet der Hoster technisch notwendige Verbindungsdaten.\n\n## IP-Adressen bei Änderungen\n\nWer Einträge im Kalender ändert, dessen IP-Adresse wird zusammen mit der Änderung gespeichert. Zweck ist die Abwehr von Missbrauch (Nachvollziehbarkeit und Rückgängigmachen von Änderungen). IP-Adressen werden nach 90 Tagen automatisch anonymisiert.\n\n## Web-Push-Benachrichtigungen\n\nPush-Benachrichtigungen werden nur nach ausdrücklicher Anmeldung versendet. Dabei wird die vom Browser erzeugte Abo-Adresse gespeichert. Das Abo kann jederzeit in den Browser-Einstellungen oder über die Glocke im Kalender beendet werden.\n\n## Nutzungsstatistik\n\nEs werden ausschließlich aggregierte Zähler (z. B. Seitenaufrufe pro Tag) ohne IP-Adressen, ohne Cookies und ohne Nutzerprofile erfasst.', NULL);

INSERT INTO setting (`key`, `value`) VALUES
('alarm_email', ''),
('ip_aufbewahrung_tage', '90'),
('vereinsname', 'Vereinskalender');
