<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Exceptions\HttpException;
use App\Core\Http\Pipeline;
use App\Core\Http\Request;
use App\Core\Http\Response;
use DI\Container;
use JsonSerializable;
use Throwable;
use UnexpectedValueException;

/**
 * Point d'entrée HTTP : middlewares globaux → routage → middlewares de route → contrôleur.
 */
final class Kernel
{
    public function __construct(
        private readonly Container $container,
        private readonly Router $router,
        private readonly Config $config,
        private readonly Pipeline $pipeline,
        private readonly ErrorRenderer $errors,
    ) {
    }

    public function handle(Request $request): Response
    {
        try {
            $response = $this->pipeline->run(
                $request,
                $this->config->get('app.middleware', []),
                fn (Request $req): Response => $this->dispatchSafely($req),
            );
        } catch (Throwable $e) {
            // Erreur levée par un middleware global : rendue sans repasser par la chaîne.
            $response = $this->errors->render($e, $request);
        }

        if ($request->isMethod('HEAD')) {
            $response = new Response('', $response->status(), $response->headers());
        }

        return $response;
    }

    /**
     * Les erreurs du routage et des contrôleurs sont converties en réponse ici,
     * pour qu'elles traversent quand même les middlewares globaux (en-têtes de sécurité, etc.).
     */
    private function dispatchSafely(Request $request): Response
    {
        try {
            return $this->dispatch($request);
        } catch (Throwable $e) {
            return $this->errors->render($e, $request);
        }
    }

    private function dispatch(Request $request): Response
    {
        $route = $this->router->match($request->method(), $request->path());

        if ($route['status'] === 'not_found') {
            throw HttpException::notFound();
        }

        if ($route['status'] === 'method_not_allowed') {
            throw new HttpException(405, 'Méthode non autorisée', ['Allow' => implode(', ', $route['allowed'])]);
        }

        foreach ($route['params'] as $key => $value) {
            $request = $request->withAttribute($key, $value);
        }

        return $this->pipeline->run(
            $request,
            $route['middleware'],
            fn (Request $req): Response => $this->callHandler($route['handler'], $route['params'], $req),
        );
    }

    /** @param array<string, string> $params */
    private function callHandler(mixed $handler, array $params, Request $request): Response
    {
        // La requête finale (enrichie par les middlewares) est injectable dans les contrôleurs.
        $this->container->set(Request::class, $request);

        if (is_array($handler) && is_string($handler[0] ?? null)) {
            $handler = [$this->container->get($handler[0]), $handler[1]];
        }

        $result = $this->container->call($handler, $params);

        return $this->toResponse($result);
    }

    private function toResponse(mixed $result): Response
    {
        return match (true) {
            $result instanceof Response => $result,
            is_string($result) => Response::html($result),
            is_array($result), $result instanceof JsonSerializable => Response::json($result),
            default => throw new UnexpectedValueException('Le contrôleur doit renvoyer une Response, une chaîne ou un tableau.'),
        };
    }
}
