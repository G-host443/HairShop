<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Exceptions\HttpException;
use App\Core\Http\MiddlewareInterface;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Router;
use App\Core\Session\Session;
use App\Services\Auth\AuthService;

/**
 * Réserve les routes de l'administration aux comptes admin / collaborateur connectés.
 * L'utilisateur connecté est ajouté à la requête (attribut « user »).
 */
final class Authenticate implements MiddlewareInterface
{
    public const INTENDED_URL = 'auth.intended_url';

    public function __construct(
        private readonly AuthService $auth,
        private readonly Session $session,
        private readonly Router $router,
    ) {
    }

    public function process(Request $request, callable $next): Response
    {
        $user = $this->auth->user();

        // Compte cliente connecté : pas d'accès à l'administration, mais on ne ferme pas sa session.
        if ($user !== null && !$user->canAccessAdmin()) {
            throw HttpException::forbidden('Cet espace est réservé à l\'administration du salon.');
        }

        if ($user === null) {
            if ($request->wantsJson()) {
                return Response::json(['error' => ['status' => 401, 'message' => 'Authentification requise']], 401);
            }

            // Retour à la page demandée après connexion (uniquement pour une navigation GET).
            if ($request->isMethod('GET')) {
                $query = $request->allQuery();
                $this->session->put(self::INTENDED_URL, $request->path() . ($query !== [] ? '?' . http_build_query($query) : ''));
            }
            $this->session->flash('info', 'Connectez-vous pour accéder à l\'administration.');

            return Response::redirect($this->router->url('admin.login'));
        }

        return $next($request->withAttribute('user', $user));
    }
}
