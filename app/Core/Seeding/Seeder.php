<?php

declare(strict_types=1);

namespace App\Core\Seeding;

use App\Core\Database;

/**
 * Seeder de données. Doit être idempotent : relancer db:seed ne crée pas de doublons
 * (utiliser ON CONFLICT … DO NOTHING / DO UPDATE).
 */
abstract class Seeder
{
    public function __construct(protected readonly Database $db)
    {
    }

    abstract public function run(): void;

    /**
     * Identifiant d'une ligne repérée par une colonne unique.
     */
    protected function idOf(string $table, string $column, string|int $value): int
    {
        $id = $this->db->fetchValue("SELECT id FROM {$table} WHERE {$column} = :v", ['v' => $value]);
        if ($id === null) {
            throw new \RuntimeException("Ligne introuvable : {$table}.{$column} = {$value}");
        }

        return (int) $id;
    }
}
