<?php

declare(strict_types=1);

namespace App\Core\Console\Commands;

use App\Core\Console\Command;
use App\Core\Seeding\SeederRunner;

final class DbSeedCommand extends Command
{
    public function __construct(private readonly SeederRunner $seeders)
    {
    }

    public function name(): string
    {
        return 'db:seed';
    }

    public function description(): string
    {
        return 'Insère les données de référence (--demo : ajoute les données de démonstration, --class=Nom : un seul seeder)';
    }

    public function handle(array $arguments, array $options): int
    {
        $classes = isset($options['class']) && is_string($options['class'])
            ? [$this->seeders->resolve($options['class'])]
            : $this->seeders->defaultSeeders(isset($options['demo']));

        $this->seeders->run($classes, fn (string $class) => $this->output->info("  ✔ {$class}"));
        $this->output->line(count($classes) . ' seeder(s) exécuté(s).');

        return 0;
    }
}
