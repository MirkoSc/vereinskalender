-- Technical table, NOT a projection (CLAUDE.md section 4).
-- Bootstrap rule: the credentials in shared/config.php are only accepted
-- while this table is empty (enforced in AuthService, no flag/state).

CREATE TABLE admin (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(64) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    erstellt_am DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
