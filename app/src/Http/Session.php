<?php

declare(strict_types=1);

namespace App\Http;

/**
 * PHP session wrapper for the admin area. Public read access never starts
 * a session (CLAUDE.md section 6); only admin routes use this class.
 */
final class Session
{
    public function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_set_cookie_params([
                'httponly' => true,
                'samesite' => 'Lax',
                'path' => '/',
            ]);
            session_start();
        }
    }

    public function loginAdmin(int $adminId, string $username): void
    {
        session_regenerate_id(true);
        unset($_SESSION['bootstrap']);
        $_SESSION['admin_id'] = $adminId;
        $_SESSION['admin_username'] = $username;
    }

    public function loginBootstrap(): void
    {
        session_regenerate_id(true);
        $_SESSION['bootstrap'] = true;
    }

    public function logout(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
            session_destroy();
        }
    }

    public function isAdmin(): bool
    {
        return isset($_SESSION['admin_id']);
    }

    public function isBootstrap(): bool
    {
        return ($_SESSION['bootstrap'] ?? false) === true;
    }

    public function adminUsername(): ?string
    {
        $username = $_SESSION['admin_username'] ?? null;

        return is_string($username) ? $username : null;
    }

    public function csrfToken(): string
    {
        return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }

    /**
     * Token from the _csrf form field or the X-CSRF-Token header (fetch).
     */
    public function checkCsrf(Request $request): bool
    {
        $token = $request->post['_csrf'] ?? $request->header('x-csrf-token') ?? '';
        $known = $_SESSION['csrf'] ?? '';

        return is_string($token) && $token !== '' && is_string($known) && $known !== ''
            && hash_equals($known, $token);
    }

    public function flash(string $message): void
    {
        $_SESSION['flash'] = $message;
    }

    public function pullFlash(): ?string
    {
        $message = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);

        return is_string($message) ? $message : null;
    }
}
