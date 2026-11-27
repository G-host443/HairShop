<?php

declare(strict_types=1);

namespace App\Services\Settings;

use App\Core\Database;
use Throwable;

/**
 * Paramètres du site (table site_settings).
 *
 * Lecture : une seule requête par processus, puis cache fichier (storage/cache/settings.php)
 * pour éviter la requête à chaque page. Le cache est supprimé à chaque modification.
 */
final class SettingsService
{
    /** @var array<string, mixed>|null */
    private ?array $values = null;

    /**
     * @param string $cacheFile fichier de cache ; chaîne vide = pas de cache fichier (tests)
     */
    public function __construct(private readonly Database $db, private readonly string $cacheFile = '')
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->load();

        return array_key_exists($key, $this->values) && $this->values[$key] !== null
            ? $this->values[$key]
            : $default;
    }

    public function int(string $key, int $default): int
    {
        $value = $this->get($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->get($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        return (bool) $this->get($key, $default);
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        $this->load();

        return $this->values;
    }

    /**
     * Enregistre plusieurs valeurs dans une transaction.
     *
     * @param array<string, mixed>  $values
     * @param array<string, string> $groups clé => groupe (pour les nouvelles clés)
     * @return array<string, array{old: mixed, new: mixed}> valeurs réellement modifiées
     */
    public function update(array $values, array $groups = []): array
    {
        $current = $this->freshValues();
        $changes = [];

        $this->db->transaction(function () use ($values, $groups, $current, &$changes): void {
            foreach ($values as $key => $value) {
                if (array_key_exists($key, $current) && $current[$key] === $value) {
                    continue;
                }
                $this->db->execute(
                    'INSERT INTO site_settings (key, value, group_name) VALUES (:key, CAST(:value AS jsonb), :group)
                     ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value',
                    [
                        'key' => $key,
                        'value' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                        'group' => $groups[$key] ?? explode('.', $key)[0],
                    ],
                );
                $changes[$key] = ['old' => $current[$key] ?? null, 'new' => $value];
            }
        });

        if ($changes !== []) {
            $this->refresh();
        }

        return $changes;
    }

    /** Vide les caches (mémoire et fichier). */
    public function refresh(): void
    {
        $this->values = null;
        if ($this->cacheFile !== '' && is_file($this->cacheFile)) {
            @unlink($this->cacheFile);
        }
    }

    private function load(): void
    {
        if ($this->values !== null) {
            return;
        }

        if ($this->cacheFile !== '' && is_file($this->cacheFile)) {
            $cached = @include $this->cacheFile;
            if (is_array($cached)) {
                $this->values = $cached;

                return;
            }
        }

        $values = $this->freshValues();
        $this->values = $values ?? [];
        if ($values !== null) {
            $this->writeCache($values);
        }
    }

    /**
     * Valeurs lues en base ; null si la table n'existe pas encore (installation : migrations non lancées),
     * auquel cas les valeurs par défaut s'appliquent et rien n'est mis en cache.
     *
     * @return array<string, mixed>|null
     */
    private function freshValues(): ?array
    {
        try {
            $rows = $this->db->fetchAll('SELECT key, value::text AS value FROM site_settings');
        } catch (\PDOException $e) {
            if ($e->getCode() === '42P01') {
                return null;
            }
            throw $e;
        }

        $values = [];
        foreach ($rows as $row) {
            $values[$row['key']] = json_decode((string) $row['value'], true);
        }

        return $values;
    }

    /** @param array<string, mixed> $values */
    private function writeCache(array $values): void
    {
        if ($this->cacheFile === '') {
            return;
        }

        try {
            $directory = dirname($this->cacheFile);
            if (!is_dir($directory)) {
                mkdir($directory, 0775, true);
            }
            // Écriture atomique : fichier temporaire puis renommage.
            $tmp = $this->cacheFile . '.' . bin2hex(random_bytes(4)) . '.tmp';
            file_put_contents($tmp, '<?php return ' . var_export($values, true) . ';' . PHP_EOL, LOCK_EX);
            rename($tmp, $this->cacheFile);
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($this->cacheFile, true);
            }
        } catch (Throwable) {
            // Le cache est une optimisation : en cas d'échec, on relira la base.
        }
    }
}
