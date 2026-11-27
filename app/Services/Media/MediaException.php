<?php

declare(strict_types=1);

namespace App\Services\Media;

use RuntimeException;
use Throwable;

/** Erreur liée à un média, avec un message affichable tel quel à l'utilisateur. */
final class MediaException extends RuntimeException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
