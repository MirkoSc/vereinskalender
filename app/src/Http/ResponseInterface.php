<?php

declare(strict_types=1);

namespace App\Http;

interface ResponseInterface
{
    public function send(): void;
}
