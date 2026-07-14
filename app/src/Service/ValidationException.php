<?php

declare(strict_types=1);

namespace App\Service;

final class ValidationException extends \RuntimeException
{
    /**
     * @param array<string, string> $errors field name => German error message
     */
    public function __construct(private readonly array $errors)
    {
        parent::__construct('Validation failed: ' . implode('; ', $errors));
    }

    /**
     * @return array<string, string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
