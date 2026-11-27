<?php

declare(strict_types=1);

namespace App\Core\Console\Commands;

use App\Core\Console\Command;
use App\Core\Migrator;

final class MigrateStatusCommand extends Command
{
    public function __construct(private readonly Migrator $migrator)
    {
    }

    public function name(): string
    {
        return 'migrate:status';
    }

    public function description(): string
    {
        return 'Affiche l\'état de chaque migration';
    }

    public function handle(array $arguments, array $options): int
    {
        $applied = $this->migrator->applied();
        $rows = [];

        foreach (array_keys($this->migrator->files()) as $version) {
            $rows[] = isset($applied[$version])
                ? ['oui', (string) $applied[$version]['batch'], $version]
                : ['non', '', $version];
        }

        if ($rows === []) {
            $this->output->line('Aucun fichier de migration.');

            return 0;
        }

        $this->output->table(['Appliquée', 'Lot', 'Migration'], $rows);

        return 0;
    }
}
