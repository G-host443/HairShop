<?php

declare(strict_types=1);

namespace App\Core\Console\Commands;

use App\Core\Config;
use App\Core\Console\Command;
use App\Core\Database;
use App\Core\Migrator;
use App\Core\Seeding\SeederRunner;

final class MigrateFreshCommand extends Command
{
    public function __construct(
        private readonly Migrator $migrator,
        private readonly Config $config,
        private readonly Database $db,
        private readonly SeederRunner $seeders,
    ) {
    }

    public function name(): string
    {
        return 'migrate:fresh';
    }

    public function description(): string
    {
        return 'Supprime toutes les tables puis relance les migrations (--seed pour les données) — développement uniquement';
    }

    public function handle(array $arguments, array $options): int
    {
        if ($this->config->get('app.env') === 'production' && !isset($options['force'])) {
            $this->output->error('Interdit en production (ajoutez --force si vous savez ce que vous faites).');

            return 1;
        }

        $this->output->warn("Suppression de toutes les tables de la base « {$this->db->databaseName()} »…");
        $this->migrator->dropAll();

        $applied = $this->migrator->migrate(fn (string $version) => $this->output->info("  ✔ {$version}"));
        $this->output->line(count($applied) . ' migration(s) appliquée(s).');

        if (isset($options['seed'])) {
            $this->seeders->run(
                $this->seeders->defaultSeeders(isset($options['demo'])),
                fn (string $class) => $this->output->info("  ✔ {$class}"),
            );
        }

        return 0;
    }
}
