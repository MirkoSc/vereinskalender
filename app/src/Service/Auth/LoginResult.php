<?php

declare(strict_types=1);

namespace App\Service\Auth;

final readonly class LoginResult
{
    private function __construct(
        public bool $isAdmin,
        public bool $isBootstrap,
        public ?int $adminId = null,
        public ?string $username = null,
    ) {
    }

    public static function admin(int $adminId, string $username): self
    {
        return new self(isAdmin: true, isBootstrap: false, adminId: $adminId, username: $username);
    }

    public static function bootstrap(): self
    {
        return new self(isAdmin: false, isBootstrap: true);
    }

    public static function failed(): self
    {
        return new self(isAdmin: false, isBootstrap: false);
    }

    public function succeeded(): bool
    {
        return $this->isAdmin || $this->isBootstrap;
    }
}
