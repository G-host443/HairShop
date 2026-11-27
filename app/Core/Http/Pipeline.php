<?php

declare(strict_types=1);

namespace App\Core\Http;

use InvalidArgumentException;
use Psr\Container\ContainerInterface;

/**
 * Enchaîne les middlewares autour d'un gestionnaire final (le contrôleur).
 *
 * Une entrée peut être une instance, un nom de classe, ou « Classe:arg1,arg2 »
 * pour un middleware paramétré (ParameterizedMiddleware).
 */
final class Pipeline
{
    public function __construct(private readonly ContainerInterface $container)
    {
    }

    /**
     * @param list<string|MiddlewareInterface> $middleware
     * @param callable(Request): Response $destination
     */
    public function run(Request $request, array $middleware, callable $destination): Response
    {
        $next = $destination;

        foreach (array_reverse($middleware) as $entry) {
            $instance = $this->resolve($entry);
            $next = static fn (Request $req): Response => $instance->process($req, $next);
        }

        return $next($request);
    }

    private function resolve(string|MiddlewareInterface $entry): MiddlewareInterface
    {
        if ($entry instanceof MiddlewareInterface) {
            return $entry;
        }

        [$class, $arguments] = array_pad(explode(':', $entry, 2), 2, null);
        $instance = $this->container->get($class);

        if (!$instance instanceof MiddlewareInterface) {
            throw new InvalidArgumentException("{$class} n'est pas un middleware.");
        }

        if ($arguments !== null) {
            if (!$instance instanceof ParameterizedMiddleware) {
                throw new InvalidArgumentException("{$class} n'accepte pas d'arguments.");
            }
            $instance = $instance->withArguments(explode(',', $arguments));
        }

        return $instance;
    }
}
