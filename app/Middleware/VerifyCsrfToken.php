<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Exceptions\HttpException;
use App\Core\Http\MiddlewareInterface;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Security\Csrf;

/**
 * Exige un jeton CSRF valide pour toute requête qui modifie des données.
 * Jeton lu dans le champ « _token » (formulaires) ou l'en-tête « X-CSRF-Token » (fetch).
 */
final class VerifyCsrfToken implements MiddlewareInterface
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(private readonly Csrf $csrf)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        if (!in_array($request->method(), self::SAFE_METHODS, true)) {
            // Corps trop volumineux : PHP l'a ignoré, le jeton est donc absent. On l'explique au lieu d'un 419.
            if ($request->exceedsPostMaxSize()) {
                throw new HttpException(413, 'Les fichiers envoyés sont trop volumineux (maximum ' . ini_get('post_max_size') . ' par envoi).');
            }

            $token = $request->input('_token') ?? $request->header('x-csrf-token');

            if (!$this->csrf->isValid(is_string($token) ? $token : null)) {
                throw new HttpException(419, 'Votre session a expiré. Rechargez la page et réessayez.');
            }
        }

        return $next($request);
    }
}
