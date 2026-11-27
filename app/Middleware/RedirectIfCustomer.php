<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Http\MiddlewareInterface;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Services\Auth\AuthService;

/** Pages de connexion / inscription : une cliente déjà connectée va directement à son espace. */
final class RedirectIfCustomer implements MiddlewareInterface
{
    public function __construct(private readonly AuthService $auth)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        if ($this->auth->user()?->isCustomer()) {
            return Response::redirect('/compte', 302);
        }

        return $next($request);
    }
}
