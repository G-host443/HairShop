<?php

declare(strict_types=1);

namespace App\Core\Console\Commands;

use App\Core\Console\Command;
use App\Core\Migrator;

final class MigrateRollbackCommand extends Command
{
    public function __construct(private readonly Migrator $migrator)
    {
    }

    public function name(): string
    {
        return 'migrate:rollback';
    }

    public function description(): string
    {
        return 'Annule le dernier lot de migrations (--step=N pour plusieurs lots)';
    }

    public function handle(array $arguments, array $options): int
    {
        $steps = isset($options['step']) ? max(1, (int) $options['step']) : 1;
        $done = $this->migrator->rollback($steps, fn (string $version) => $this->output->warn("  ↺ {$version}"));

        $this->output->line($done === [] ? 'Aucune migration à annuler.' : count($done) . ' migration(s) annulée(s).');

        return 0;
    }
}
