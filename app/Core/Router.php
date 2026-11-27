<?php

declare(strict_types=1);

namespace App\Core;

use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use InvalidArgumentException;

use function FastRoute\simpleDispatcher;

/**
 * Déclaration des routes (groupes, middlewares, noms) et résolution via FastRoute.
 *
 * Gestionnaire d'une route : [Controller::class, 'methode'] ou une closure.
 */
final class Router
{
    /** @var list<array{method: string, path: string, handler: mixed, middleware: list<string>, name: ?string}> */
    private array $routes = [];

    /** @var array<string, string> nom => motif */
    private array $named = [];

    private string $prefix = '';

    /** @var list<string> */
    private array $groupMiddleware = [];

    private ?Dispatcher $dispatcher = null;

    public function get(string $path, mixed $handler, ?string $name = null, array $middleware = []): self
    {
        return $this->add(['GET', 'HEAD'], $path, $handler, $name, $middleware);
    }

    public function post(string $path, mixed $handler, ?string $name = null, array $middleware = []): self
    {
        return $this->add(['POST'], $path, $handler, $name, $middleware);
    }

    public function put(string $path, mixed $handler, ?string $name = null, array $middleware = []): self
    {
        return $this->add(['PUT'], $path, $handler, $name, $middleware);
    }

    public function patch(string $path, mixed $handler, ?string $name = null, array $middleware = []): self
    {
        return $this->add(['PATCH'], $path, $handler, $name, $middleware);
    }

    public function delete(string $path, mixed $handler, ?string $name = null, array $middleware = []): self
    {
        return $this->add(['DELETE'], $path, $handler, $name, $middleware);
    }

    /**
     * @param array{prefix?: string, middleware?: list<string>} $attributes
     * @param callable(self): void $routes
     */
    public function group(array $attributes, callable $routes): void
    {
        $previousPrefix = $this->prefix;
        $previousMiddleware = $this->groupMiddleware;

        $this->prefix = $this->normalize($previousPrefix . ($attributes['prefix'] ?? ''));
        $this->groupMiddleware = array_merge($previousMiddleware, $attributes['middleware'] ?? []);

        try {
            $routes($this);
        } finally {
            $this->prefix = $previousPrefix;
            $this->groupMiddleware = $previousMiddleware;
        }
    }

    /**
     * @param list<string> $methods
     * @param list<string> $middleware
     */
    private function add(array $methods, string $path, mixed $handler, ?string $name, array $middleware): self
    {
        $fullPath = $this->normalize($this->prefix . $path);

        foreach ($methods as $method) {
            $this->routes[] = [
                'method' => $method,
                'path' => $fullPath,
                'handler' => $handler,
                'middleware' => array_merge($this->groupMiddleware, $middleware),
                'name' => $name,
            ];
        }

        if ($name !== null) {
            if (isset($this->named[$name])) {
                throw new InvalidArgumentException("Nom de route déjà utilisé : {$name}");
            }
            $this->named[$name] = $fullPath;
        }

        $this->dispatcher = null;

        return $this;
    }

    /**
     * @return array{status: 'found', handler: mixed, middleware: list<string>, params: array<string, string>}
     *        |array{status: 'not_found'}
     *        |array{status: 'method_not_allowed', allowed: list<string>}
     */
    public function match(string $method, string $path): array
    {
        $this->dispatcher ??= simpleDispatcher(function (RouteCollector $collector): void {
            foreach ($this->routes as $index => $route) {
                $collector->addRoute($route['method'], $route['path'], $index);
            }
        });

        $result = $this->dispatcher->dispatch($method, $this->normalize($path));

        return match ($result[0]) {
            Dispatcher::FOUND => [
                'status' => 'found',
                'handler' => $this->routes[$result[1]]['handler'],
                'middleware' => $this->routes[$result[1]]['middleware'],
                'params' => $result[2],
            ],
            Dispatcher::METHOD_NOT_ALLOWED => ['status' => 'method_not_allowed', 'allowed' => $result[1]],
            default => ['status' => 'not_found'],
        };
    }

    /**
     * Génère l'URL d'une route nommée : url('services.show', ['slug' => 'knotless']).
     *
     * @param array<string, scalar> $params  paramètres de route ; le surplus devient la query string
     */
    public function url(string $name, array $params = []): string
    {
        if (!isset($this->named[$name])) {
            throw new InvalidArgumentException("Route inconnue : {$name}");
        }

        $path = $this->build($this->named[$name], $params, false, $name) ?? '';

        return $params === [] ? $path : $path . '?' . http_build_query($params);
    }

    /**
     * Remplace les paramètres d'un motif FastRoute, en gérant les segments optionnels imbriqués
     * (« /blog[/page/{page:\d+}] ») et les expressions régulières contenant des accolades ou crochets.
     *
     * @param array<string, scalar> $params  les paramètres utilisés sont retirés du tableau
     * @return string|null null si un paramètre manque dans un segment optionnel
     */
    private function build(string $pattern, array &$params, bool $optional, string $route): ?string
    {
        $out = '';
        $length = strlen($pattern);

        for ($i = 0; $i < $length; $i++) {
            $char = $pattern[$i];

            if ($char === '{') {
                $end = $this->matching($pattern, $i, '{', '}');
                $placeholder = substr($pattern, $i + 1, $end - $i - 1);
                $param = explode(':', $placeholder, 2)[0];
                if (!array_key_exists($param, $params)) {
                    if ($optional) {
                        return null;
                    }
                    throw new InvalidArgumentException("Paramètre « {$param} » manquant pour la route {$route}");
                }
                $out .= rawurlencode((string) $params[$param]);
                unset($params[$param]);
                $i = $end;
            } elseif ($char === '[') {
                $end = $this->matching($pattern, $i, '[', ']');
                $backup = $params;
                $segment = $this->build(substr($pattern, $i + 1, $end - $i - 1), $params, true, $route);
                if ($segment === null) {
                    $params = $backup; // segment omis : ses paramètres éventuels restent en query string
                    break;             // FastRoute n'autorise les segments optionnels qu'en fin de motif
                }
                $out .= $segment;
                $i = $end;
            } else {
                $out .= $char;
            }
        }

        return $out;
    }

    /** Position du délimiteur fermant correspondant, en ignorant ceux des expressions régulières imbriquées. */
    private function matching(string $pattern, int $start, string $open, string $close): int
    {
        $depth = 0;
        $braces = 0;
        $length = strlen($pattern);

        for ($i = $start; $i < $length; $i++) {
            $char = $pattern[$i];
            if ($open === '[' && $char === '{') {
                $braces++;
            } elseif ($open === '[' && $char === '}') {
                $braces--;
            } elseif ($braces === 0 && $char === $open) {
                $depth++;
            } elseif ($braces === 0 && $char === $close) {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        throw new InvalidArgumentException("Motif de route mal formé : {$pattern}");
    }

    public function has(string $name): bool
    {
        return isset($this->named[$name]);
    }

    private function normalize(string $path): string
    {
        $path = '/' . trim($path, '/');

        return $path === '/' ? '/' : rtrim($path, '/');
    }
}
