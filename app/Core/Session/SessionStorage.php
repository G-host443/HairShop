<?php

declare(strict_types=1);

namespace App\Core\Session;

/**
 * Stockage bas niveau de la session : sessions PHP natives en production,
 * tableau en mémoire pour les tests.
 */
interface SessionStorage
{
    public function start(bool $secure): void;

    public function isStarted(): bool;

    /** @return array<string, mixed> */
    public function &data(): array;

    /** Nouvel identifiant de session (contre la fixation de session). */
    public function regenerate(): void;

    /** Vide et détruit la session courante. */
    public function destroy(): void;

    public function id(): string;
}
