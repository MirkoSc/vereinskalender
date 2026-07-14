<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\HttpMethod;
use App\Http\MatchType;
use App\Http\Response;
use App\Http\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    private static function handler(): \Closure
    {
        return static fn(): Response => Response::html('ok');
    }

    public function testMatchesStaticRoute(): void
    {
        $router = new Router();
        $router->get('/', self::handler());

        $match = $router->match(HttpMethod::Get, '/');

        self::assertSame(MatchType::Matched, $match->type);
        self::assertSame([], $match->params);
    }

    public function testExtractsParamBeforeLiteralSuffix(): void
    {
        $router = new Router();
        $router->get('/export/team/{id}.ics', self::handler());

        $match = $router->match(HttpMethod::Get, '/export/team/42.ics');

        self::assertSame(MatchType::Matched, $match->type);
        self::assertSame(['id' => '42'], $match->params);
    }

    public function testCustomConstraintRejectsNonMatchingParam(): void
    {
        $router = new Router();
        $router->get('/team/{id:\d+}', self::handler());

        self::assertSame(MatchType::Matched, $router->match(HttpMethod::Get, '/team/7')->type);
        self::assertSame(MatchType::NotFound, $router->match(HttpMethod::Get, '/team/abc')->type);
    }

    public function testUnknownPathIsNotFound(): void
    {
        $router = new Router();
        $router->get('/', self::handler());

        self::assertSame(MatchType::NotFound, $router->match(HttpMethod::Get, '/gibtsnicht')->type);
    }

    public function testWrongMethodIsMethodNotAllowedWithAllowHeaderData(): void
    {
        $router = new Router();
        $router->get('/kalender', self::handler());

        $match = $router->match(HttpMethod::Post, '/kalender');

        self::assertSame(MatchType::MethodNotAllowed, $match->type);
        self::assertSame([HttpMethod::Get], $match->allowedMethods);
    }

    public function testDecodesExtractedParams(): void
    {
        $router = new Router();
        $router->get('/export/team/{id}.ics', self::handler());

        $match = $router->match(HttpMethod::Get, '/export/team/%C3%A4bc.ics');

        self::assertSame(['id' => 'äbc'], $match->params);
    }

    public function testHeadIsTreatedAsGet(): void
    {
        self::assertSame(HttpMethod::Get, HttpMethod::fromString('HEAD'));
    }
}
