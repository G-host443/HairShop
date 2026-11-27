<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;
use Throwable;

/**
 * Migrations SQL versionnées.
 *
 * Fichier : database/migrations/AAAA_MM_JJ_HHMMSS_description.sql
 *
 *     -- migrate:up
 *     CREATE TABLE ...;
 *     -- migrate:down
 *     DROP TABLE ...;
 *
 * Chaque migration est exécutée dans sa propre transaction (le DDL est transactionnel sous PostgreSQL).
 */
final class Migrator
{
    private const TABLE = 'schema_migrations';

    public function __construct(
        private readonly Database $db,
        private readonly string $path,
    ) {
    }

    /**
     * @return array{up: string, down: string}
     */
    public static function parse(string $sql, string $file = ''): array
    {
        $parts = preg_split('/^\s*--\s*migrate:(up|down)\s*$/mi', $sql, -1, PREG_SPLIT_DELIM_CAPTURE);
        $sections = ['up' => null, 'down' => null];

        for ($i = 1; $i < count($parts); $i += 2) {
            $sections[strtolower($parts[$i])] = trim($parts[$i + 1] ?? '');
        }

        if ($sections['up'] === null || $sections['up'] === '') {
            throw new RuntimeException("Migration sans section « -- migrate:up » : {$file}");
        }

        return ['up' => $sections['up'], 'down' => (string) $sections['down']];
    }

    /** @return array<string, string> version => chemin, triés */
    public function files(): array
    {
        $files = [];
        foreach (glob(rtrim($this->path, '/\\') . '/*.sql') ?: [] as $file) {
            $files[basename($file, '.sql')] = $file;
        }
        ksort($files, SORT_STRING);

        return $files;
    }

    /** @return array<string, array{batch: int, applied_at: string}> */
    public function applied(): array
    {
        $this->ensureTable();
        $rows = $this->db->fetchAll('SELECT version, batch, applied_at FROM ' . self::TABLE . ' ORDER BY version');

        $applied = [];
        foreach ($rows as $row) {
            $applied[$row['version']] = ['batch' => (int) $row['batch'], 'applied_at' => (string) $row['applied_at']];
        }

        return $applied;
    }

    /** @return list<string> */
    public function pending(): array
    {
        return array_values(array_diff(array_keys($this->files()), array_keys($this->applied())));
    }

    /**
     * Applique toutes les migrations en attente dans un même lot.
     *
     * @param callable(string): void|null $onMigrated
     * @return list<string> versions appliquées
     */
    public function migrate(?callable $onMigrated = null): array
    {
        $pending = $this->pending();
        if ($pending === []) {
            return [];
        }

        $files = $this->files();
        $batch = (int) $this->db->fetchValue('SELECT COALESCE(MAX(batch), 0) + 1 FROM ' . self::TABLE);

        foreach ($pending as $version) {
            $sql = self::parse((string) file_get_contents($files[$version]), $files[$version]);
            $this->runInTransaction($version, $sql['up'], function () use ($version, $batch): void {
                $this->db->execute('INSERT INTO ' . self::TABLE . ' (version, batch) VALUES (:v, :b)', ['v' => $version, 'b' => $batch]);
            });
            if ($onMigrated !== null) {
                $onMigrated($version);
            }
        }

        return $pending;
    }

    /**
     * Annule les $steps derniers lots.
     *
     * @param callable(string): void|null $onRolledBack
     * @return list<string> versions annulées
     */
    public function rollback(int $steps = 1, ?callable $onRolledBack = null): array
    {
        $this->ensureTable();
        $versions = $this->db->fetchAll(
            'SELECT version FROM ' . self::TABLE . ' WHERE batch > (SELECT COALESCE(MAX(batch), 0) FROM ' . self::TABLE . ') - CAST(:steps AS bigint) ORDER BY version DESC',
            ['steps' => max(1, $steps)],
        );

        $files = $this->files();
        $rolledBack = [];

        foreach (array_column($versions, 'version') as $version) {
            if (!isset($files[$version])) {
                throw new RuntimeException("Fichier de migration introuvable pour annuler : {$version}");
            }
            $sql = self::parse((string) file_get_contents($files[$version]), $files[$version]);
            if ($sql['down'] === '') {
                throw new RuntimeException("La migration {$version} n'a pas de section « -- migrate:down ».");
            }
            $this->runInTransaction($version, $sql['down'], function () use ($version): void {
                $this->db->execute('DELETE FROM ' . self::TABLE . ' WHERE version = :v', ['v' => $version]);
            });
            $rolledBack[] = $version;
            if ($onRolledBack !== null) {
                $onRolledBack($version);
            }
        }

        return $rolledBack;
    }

    /**
     * Supprime tout le schéma public (réservé au développement : migrate:fresh).
     */
    public function dropAll(): void
    {
        $this->db->unprepared('DROP SCHEMA public CASCADE; CREATE SCHEMA public;');
    }

    private function runInTransaction(string $version, string $sql, callable $record): void
    {
        try {
            $this->db->transaction(function () use ($sql, $record): void {
                $this->db->unprepared($sql);
                $record();
            });
        } catch (Throwable $e) {
            throw new RuntimeException("Échec de la migration {$version} : " . $e->getMessage(), 0, $e);
        }
    }

    private function ensureTable(): void
    {
        $this->db->unprepared(
            'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (
                version    text PRIMARY KEY,
                batch      integer NOT NULL,
                applied_at timestamptz NOT NULL DEFAULT now()
            )'
        );
    }
}
