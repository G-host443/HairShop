<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Exceptions\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Kernel;
use App\Core\Router;
use DI\Container;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Teste la chaîne complète Kernel → routeur → contrôleur → rendu des erreurs, sans base de données.
 */
final class KernelTest extends TestCase
{
    private Container $container;
    private Router $router;

    protected function setUp(): void
    {
        $this->container = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        // Routeur vierge pour isoler les tests des routes réelles.
        $this->router = new Router();
        $this->container->set(Router::class, $this->router);
    }

    private function kernel(): Kernel
    {
        return $this->container->make(Kernel::class);
    }

    public function testRouteParametersAreInjectedIntoHandler(): void
    {
        $this->router->get('/bonjour/{name}', static fn (string $name): string => "Bonjour {$name}");

        $response = $this->kernel()->handle(new Request('GET', '/bonjour/Garance'));

        self::assertSame(200, $response->status());
        self::assertSame('Bonjour Garance', $response->content());
    }

    public function testArraysAreReturnedAsJson(): void
    {
        $this->router->get('/api/ping', static fn (): array => ['pong' => true]);

        $response = $this->kernel()->handle(new Request('GET', '/api/ping'));

        self::assertSame('{"pong":true}', $response->content());
        self::assertStringStartsWith('application/json', (string) $response->header('Content-Type'));
    }

    public function testUnknownRouteRendersHtml404WithSecurityHeaders(): void
    {
        $response = $this->kernel()->handle(new Request('GET', '/nexiste-pas'));

        self::assertSame(404, $response->status());
        self::assertStringContainsString("Cette page n'existe pas", $response->content());
        self::assertSame('nosniff', $response->header('X-Content-Type-Options'));
        self::assertNotNull($response->header('Content-Security-Policy'));
    }

    public function testWrongMethodReturns405WithAllowHeader(): void
    {
        $this->router->post('/reserver', static fn (): string => 'ok');

        $response = $this->kernel()->handle(new Request('GET', '/reserver'));

        self::assertSame(405, $response->status());
        self::assertSame('POST', $response->header('Allow'));
    }

    public function testApiErrorsAreJson(): void
    {
        $this->router->get('/api/interdit', static fn () => throw HttpException::forbidden());

        $response = $this->kernel()->handle(new Request('GET', '/api/interdit'));

        self::assertSame(403, $response->status());
        self::assertSame(['error' => ['status' => 403, 'message' => 'Accès refusé']], json_decode($response->content(), true));
    }

    public function testUnexpectedExceptionBecomes500(): void
    {
        $this->router->get('/panne', static fn () => throw new RuntimeException('détail interne'));

        $response = $this->kernel()->handle(new Request('GET', '/panne'));

        self::assertSame(500, $response->status());
    }

    public function testHeadRequestHasNoBody(): void
    {
        $this->router->get('/page', static fn (): string => 'contenu');

        $response = $this->kernel()->handle(new Request('HEAD', '/page'));

        self::assertSame(200, $response->status());
        self::assertSame('', $response->content());
    }
}
