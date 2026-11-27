<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Http\MiddlewareInterface;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Router;
use App\Services\Auth\AuthService;

/**
 * Pages réservées aux visiteurs non connectés (connexion, mot de passe oublié) :
 * un admin déjà connecté est renvoyé vers le tableau de bord.
 */
final class RedirectIfAuthenticated implements MiddlewareInterface
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly Router $router,
    ) {
    }

    public function process(Request $request, callable $next): Response
    {
        if ($this->auth->user()?->canAccessAdmin()) {
            return Response::redirect($this->router->url('admin.dashboard'));
        }

        return $next($request);
    }
}
