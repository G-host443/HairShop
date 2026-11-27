<?php

declare(strict_types=1);

namespace App\Core\Session;

/**
 * Session applicative : valeurs, messages flash (affichés une seule fois),
 * anciennes saisies et erreurs de validation pour le schéma POST → redirection → GET.
 */
final class Session
{
    private const FLASH_NEW = '_flash.new';
    private const FLASH_OLD = '_flash.old';

    public function __construct(private readonly SessionStorage $storage)
    {
    }

    public function start(bool $secure): void
    {
        $this->storage->start($secure);
    }

    public function isStarted(): bool
    {
        return $this->storage->isStarted();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data()[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data());
    }

    public function put(string $key, mixed $value): void
    {
        $data = &$this->storage->data();
        $data[$key] = $value;
    }

    public function forget(string $key): void
    {
        $data = &$this->storage->data();
        unset($data[$key]);
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);
        $this->forget($key);

        return $value;
    }

    /**
     * Valeur disponible pour la requête suivante uniquement.
     */
    public function flash(string $key, mixed $value): void
    {
        $this->put($key, $value);
        $new = $this->get(self::FLASH_NEW, []);
        $new[] = $key;
        $this->put(self::FLASH_NEW, array_values(array_unique($new)));
        $this->put(self::FLASH_OLD, array_values(array_diff($this->get(self::FLASH_OLD, []), [$key])));
    }

    /**
     * À appeler une fois par requête, au démarrage : supprime les flash de la requête
     * précédente et marque ceux qui viennent d'être créés comme « à supprimer la prochaine fois ».
     */
    public function ageFlashData(): void
    {
        foreach ($this->get(self::FLASH_OLD, []) as $key) {
            $this->forget($key);
        }
        $this->put(self::FLASH_OLD, $this->get(self::FLASH_NEW, []));
        $this->put(self::FLASH_NEW, []);
    }

    /**
     * Mémorise la saisie d'un formulaire pour la réafficher, sans jamais conserver les mots de passe.
     *
     * @param array<string, mixed> $input
     */
    public function flashInput(array $input): void
    {
        $safe = array_filter(
            $input,
            static fn ($value, $key): bool => !str_contains((string) $key, 'password') && $key !== '_token',
            ARRAY_FILTER_USE_BOTH,
        );
        $this->flash('_old_input', $safe);
    }

    public function old(string $key, mixed $default = null): mixed
    {
        return $this->get('_old_input', [])[$key] ?? $default;
    }

    /** @param array<string, list<string>> $errors */
    public function flashErrors(array $errors): void
    {
        $this->flash('_errors', $errors);
    }

    /** @return array<string, list<string>> */
    public function errors(): array
    {
        return $this->get('_errors', []);
    }

    public function regenerate(): void
    {
        $this->storage->regenerate();
    }

    public function invalidate(): void
    {
        $this->storage->destroy();
    }

    public function id(): string
    {
        return $this->storage->id();
    }

    /** @return array<string, mixed> */
    private function data(): array
    {
        return $this->storage->data();
    }
}
