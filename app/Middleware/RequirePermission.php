<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Exceptions\HttpException;
use App\Core\Http\ParameterizedMiddleware;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Services\Auth\AuthService;
use LogicException;

/**
 * Vérifie que l'utilisateur connecté possède toutes les permissions demandées.
 * À placer après Authenticate :  RequirePermission::class . ':catalog.manage'
 */
final class RequirePermission implements ParameterizedMiddleware
{
    /** @var list<string> */
    private array $permissions = [];

    public function __construct(private readonly AuthService $auth)
    {
    }

    public function withArguments(array $arguments): static
    {
        $clone = clone $this;
        $clone->permissions = array_values(array_filter(array_map('trim', $arguments)));

        return $clone;
    }

    public function process(Request $request, callable $next): Response
    {
        if ($this->permissions === []) {
            throw new LogicException('RequirePermission utilisé sans permission.');
        }

        foreach ($this->permissions as $permission) {
            if (!$this->auth->can($permission)) {
                throw HttpException::forbidden('Vous n\'avez pas l\'autorisation d\'accéder à cette page.');
            }
        }

        return $next($request);
    }
}
