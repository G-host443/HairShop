<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use RuntimeException;

/** Combinaison prestation / taille / longueur / options invalide (message affichable). */
final class PricingException extends RuntimeException
{
}
