<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Http\MiddlewareInterface;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Session\Session;
use App\Services\Auth\AuthService;

/**
 * Réserve l'espace cliente (/compte) aux comptes clientes connectés.
 * L'utilisateur est ajouté à la requête (attribut « user »).
 */
final class AuthenticateCustomer implements MiddlewareInterface
{
    public const INTENDED_URL = 'account.intended_url';

    public function __construct(
        private readonly AuthService $auth,
        private readonly Session $session,
    ) {
    }

    public function process(Request $request, callable $next): Response
    {
        $user = $this->auth->user();

        if ($user === null || !$user->isCustomer()) {
            if ($request->isMethod('GET')) {
                $query = $request->allQuery();
                $this->session->put(self::INTENDED_URL, $request->path() . ($query !== [] ? '?' . http_build_query($query) : ''));
            }
            $this->session->flash('info', 'Connectez-vous pour accéder à votre espace.');

            return Response::redirect('/compte/connexion', 302);
        }

        return $next($request->withAttribute('user', $user));
    }
}
