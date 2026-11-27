<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Http\MiddlewareInterface;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Session\Session;

/**
 * Démarre la session (cookie HttpOnly, SameSite=Lax, Secure en HTTPS) et fait vieillir les messages flash.
 * Les réponses dépendant de la session ne doivent pas être mises en cache par un proxy.
 */
final class StartSession implements MiddlewareInterface
{
    public function __construct(private readonly Session $session)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        $this->session->start($request->isSecure());
        $this->session->ageFlashData();

        $response = $next($request);

        if ($response->header('Cache-Control') === null) {
            $response = $response->withHeader('Cache-Control', 'private, no-cache');
        }

        return $response;
    }
}
