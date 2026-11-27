<?php

declare(strict_types=1);

namespace App\Core\Validation;

use RuntimeException;

/**
 * Levée quand une saisie est invalide. Convertie par l'ErrorRenderer en :
 * - redirection vers le formulaire avec erreurs et saisie conservée (HTML) ;
 * - réponse 422 avec la liste des erreurs (JSON).
 */
final class ValidationException extends RuntimeException
{
    /** @param array<string, list<string>> $errors */
    public function __construct(
        private readonly array $errors,
        private readonly ?string $redirectTo = null,
    ) {
        parent::__construct('Données invalides', 422);
    }

    /** @param array<string, list<string>|string> $errors */
    public static function withMessages(array $errors, ?string $redirectTo = null): self
    {
        return new self(array_map(static fn ($m) => (array) $m, $errors), $redirectTo);
    }

    /** @return array<string, list<string>> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function redirectTo(): ?string
    {
        return $this->redirectTo;
    }
}
