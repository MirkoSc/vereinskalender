<?php

declare(strict_types=1);

namespace App\Http;

enum HttpMethod: string
{
    case Get = 'GET';
    case Post = 'POST';
    case Put = 'PUT';
    case Patch = 'PATCH';
    case Delete = 'DELETE';

    /**
     * HEAD is dispatched like GET (Apache strips the body); unknown
     * methods fall back to GET so they end up as a regular 404/405.
     */
    public static function fromString(string $method): self
    {
        $method = strtoupper($method);
        if ($method === 'HEAD') {
            return self::Get;
        }

        return self::tryFrom($method) ?? self::Get;
    }
}
