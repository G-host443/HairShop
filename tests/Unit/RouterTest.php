<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Router;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    public function testMatchesRouteAndExtractsParameters(): void
    {
        $router = new Router();
        $router->get('/prestations/{slug}', 'handler', 'services.show');

        $match = $router->match('GET', '/prestations/knotless');

        self::assertSame('found', $match['status']);
        self::assertSame(['slug' => 'knotless'], $match['params']);
    }

    public function testTrailingSlashIsIgnored(): void
    {
        $router = new Router();
        $router->get('/galerie', 'handler');

        self::assertSame('found', $router->match('GET', '/galerie/')['status']);
    }

    public function testNotFoundAndMethodNotAllowed(): void
    {
        $router = new Router();
        $router->post('/reserver', 'handler');

        self::assertSame('not_found', $router->match('GET', '/inconnu')['status']);

        $match = $router->match('GET', '/reserver');
        self::assertSame('method_not_allowed', $match['status']);
        self::assertSame(['POST'], $match['allowed']);
    }

    public function testGroupsCombinePrefixesAndMiddleware(): void
    {
        $router = new Router();
        $router->group(['prefix' => '/admin', 'middleware' => ['auth']], static function (Router $r): void {
            $r->group(['prefix' => '/prestations', 'middleware' => ['can']], static function (Router $r): void {
                $r->get('/{id:\d+}', 'handler', 'admin.services.edit', ['extra']);
            });
        });

        $match = $router->match('GET', '/admin/prestations/12');

        self::assertSame('found', $match['status']);
        self::assertSame(['auth', 'can', 'extra'], $match['middleware']);
        self::assertSame('not_found', $router->match('GET', '/admin/prestations/abc')['status']);
    }

    public function testGeneratesUrlsWithParametersAndQueryString(): void
    {
        $router = new Router();
        $router->get('/prestations/{slug}', 'h', 'services.show');
        $router->get('/admin/prestations/{id:\d+}', 'h', 'admin.services.edit');
        $router->get('/blog[/page/{page:\d+}]', 'h', 'blog.index');

        self::assertSame('/prestations/box-twist?ref=home', $router->url('services.show', ['slug' => 'box-twist', 'ref' => 'home']));
        self::assertSame('/admin/prestations/7', $router->url('admin.services.edit', ['id' => 7]));
        self::assertSame('/blog', $router->url('blog.index'));
        self::assertSame('/blog/page/2', $router->url('blog.index', ['page' => 2]));
    }

    public function testOptionalSegmentWhoseRegexContainsBrackets(): void
    {
        $router = new Router();
        $router->get('/admin/parametres[/{group:[a-z]+}]', 'h', 'admin.settings');
        $router->get('/a/{code:[A-Z]{3}}[/{n:\d+}[/{m:\d+}]]', 'h', 'nested');

        self::assertSame('/admin/parametres', $router->url('admin.settings'));
        self::assertSame('/admin/parametres/contact', $router->url('admin.settings', ['group' => 'contact']));
        self::assertSame('/a/ABC', $router->url('nested', ['code' => 'ABC']));
        self::assertSame('/a/ABC/1/2', $router->url('nested', ['code' => 'ABC', 'n' => 1, 'm' => 2]));
        self::assertSame('found', $router->match('GET', '/admin/parametres/contact')['status']);
    }

    public function testUrlGenerationFailsOnMissingParameter(): void
    {
        $router = new Router();
        $router->get('/prestations/{slug}', 'h', 'services.show');

        $this->expectException(InvalidArgumentException::class);
        $router->url('services.show');
    }

    public function testDuplicateRouteNamesAreRejected(): void
    {
        $router = new Router();
        $router->get('/a', 'h', 'same');

        $this->expectException(InvalidArgumentException::class);
        $router->get('/b', 'h', 'same');
    }
}
