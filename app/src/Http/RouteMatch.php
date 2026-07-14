<?php

declare(strict_types=1);

namespace App\Http;

final readonly class RouteMatch
{
    /**
     * @param array<string, string> $params url-decoded route parameters
     * @param list<HttpMethod> $allowedMethods for the Allow header on 405
     */
    private function __construct(
        public MatchType $type,
        public ?\Closure $handler = null,
        public array $params = [],
        public array $allowedMethods = [],
    ) {
    }

    /**
     * @param array<string, string> $params
     */
    public static function matched(\Closure $handler, array $params): self
    {
        return new self(MatchType::Matched, $handler, $params);
    }

    public static function notFound(): self
    {
        return new self(MatchType::NotFound);
    }

    /**
     * @param list<HttpMethod> $allowed
     */
    public static function methodNotAllowed(array $allowed): self
    {
        return new self(MatchType::MethodNotAllowed, allowedMethods: $allowed);
    }
}
