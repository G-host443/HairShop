<?php

declare(strict_types=1);

namespace App\Core\Http;

/**
 * Middleware acceptant des arguments dans sa déclaration de route :
 *     RequirePermission::class . ':bookings.manage'
 */
interface ParameterizedMiddleware extends MiddlewareInterface
{
    /** @param list<string> $arguments */
    public function withArguments(array $arguments): static;
}
