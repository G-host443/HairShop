<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

use RuntimeException;

class HttpException extends RuntimeException
{
    /** @param array<string, string> $headers */
    public function __construct(
        private readonly int $statusCode,
        string $message = '',
        private readonly array $headers = [],
    ) {
        parent::__construct($message, $statusCode);
    }

    public static function notFound(string $message = 'Page introuvable'): self
    {
        return new self(404, $message);
    }

    public static function forbidden(string $message = 'Accès refusé'): self
    {
        return new self(403, $message);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }
}
