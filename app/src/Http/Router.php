<?php

declare(strict_types=1);

namespace App\Http;

final class Router
{
    /** @var list<Route> */
    private array $routes = [];

    public function get(string $pattern, \Closure $handler): void
    {
        $this->add(HttpMethod::Get, $pattern, $handler);
    }

    public function post(string $pattern, \Closure $handler): void
    {
        $this->add(HttpMethod::Post, $pattern, $handler);
    }

    public function add(HttpMethod $method, string $pattern, \Closure $handler): void
    {
        $this->routes[] = new Route($method, $pattern, $handler);
    }

    public function match(HttpMethod $method, string $path): RouteMatch
    {
        $allowed = [];
        foreach ($this->routes as $route) {
            if (preg_match($route->compile(), $path, $matches) !== 1) {
                continue;
            }
            if ($route->method !== $method) {
                if (!in_array($route->method, $allowed, true)) {
                    $allowed[] = $route->method;
                }
                continue;
            }

            $params = [];
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = rawurldecode($value);
                }
            }

            return RouteMatch::matched($route->handler, $params);
        }

        return $allowed === []
            ? RouteMatch::notFound()
            : RouteMatch::methodNotAllowed($allowed);
    }
}
