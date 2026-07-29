<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Config\Config;
use App\Repository\AdminRepository;
use App\Service\ValidationException;

/**
 * Bootstrap rule (CLAUDE.md section 6): the credentials from
 * shared/config.php are ONLY accepted while the admin table is empty.
 * As soon as one row exists they are rejected — no flag, no state.
 */
final readonly class AuthService
{
    public function __construct(
        private AdminRepository $admins,
        private Config $config,
    ) {
    }

    public function attempt(string $username, string $password): LoginResult
    {
        if ($this->admins->count() === 0) {
            if (hash_equals($this->config->bootstrapAdminUsername, $username)
                && hash_equals($this->config->bootstrapAdminPassword, $password)) {
                return LoginResult::bootstrap();
            }

            return LoginResult::failed();
        }

        $admin = $this->admins->findByUsername($username);
        if ($admin !== null && password_verify($password, $admin['password_hash'])) {
            // The plaintext is only available here, so this is the one
            // moment a stored hash can follow a PASSWORD_DEFAULT change.
            // Without it an account keeps whatever algorithm was current
            // when the password was set - forever, since nothing else ever
            // rewrites it.
            if (password_needs_rehash($admin['password_hash'], PASSWORD_DEFAULT)) {
                $this->admins->updatePasswordHash($admin['id'], password_hash($password, PASSWORD_DEFAULT));
            }

            return LoginResult::admin($admin['id'], $admin['username']);
        }

        return LoginResult::failed();
    }

    /**
     * The first login with bootstrap credentials forces creating a real
     * admin; once it exists, bootstrap credentials are dead.
     */
    public function createFirstAdmin(string $username, string $password, string $passwordRepeat): int
    {
        if ($this->admins->count() > 0) {
            throw new ValidationException(['username' => 'Es existiert bereits ein Admin.']);
        }

        $errors = [];
        $username = trim($username);
        if (mb_strlen($username) < 3 || mb_strlen($username) > 64) {
            $errors['username'] = 'Benutzername muss 3–64 Zeichen lang sein.';
        }
        if (strlen($password) < 12) {
            $errors['password'] = 'Passwort muss mindestens 12 Zeichen lang sein.';
        }
        if ($password !== $passwordRepeat) {
            $errors['password_repeat'] = 'Die Passwörter stimmen nicht überein.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $this->admins->create($username, password_hash($password, PASSWORD_DEFAULT));
    }
}
