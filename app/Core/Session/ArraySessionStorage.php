<?php

declare(strict_types=1);

namespace App\Core\Session;

/**
 * Session en mémoire (tests, console). Le contenu survit entre deux requêtes du même processus,
 * ce qui permet de simuler un parcours complet (connexion puis navigation).
 */
final class ArraySessionStorage implements SessionStorage
{
    /** @var array<string, mixed> */
    private array $data = [];
    private bool $started = false;
    private string $id;

    public function __construct()
    {
        $this->id = bin2hex(random_bytes(16));
    }

    public function start(bool $secure): void
    {
        $this->started = true;
    }

    public function isStarted(): bool
    {
        return $this->started;
    }

    public function &data(): array
    {
        return $this->data;
    }

    public function regenerate(): void
    {
        $this->id = bin2hex(random_bytes(16));
    }

    public function destroy(): void
    {
        $this->data = [];
        $this->regenerate();
    }

    public function id(): string
    {
        return $this->id;
    }
}
