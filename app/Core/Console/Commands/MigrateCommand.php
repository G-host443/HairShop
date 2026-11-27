<?php

declare(strict_types=1);

namespace App\Core\Console\Commands;

use App\Core\Console\Command;
use App\Core\Migrator;

final class MigrateCommand extends Command
{
    public function __construct(private readonly Migrator $migrator)
    {
    }

    public function name(): string
    {
        return 'migrate';
    }

    public function description(): string
    {
        return 'Applique les migrations en attente';
    }

    public function handle(array $arguments, array $options): int
    {
        $applied = $this->migrator->migrate(fn (string $version) => $this->output->info("  ✔ {$version}"));

        $this->output->line($applied === []
            ? 'Rien à migrer : la base est à jour.'
            : count($applied) . ' migration(s) appliquée(s).');

        return 0;
    }
}
