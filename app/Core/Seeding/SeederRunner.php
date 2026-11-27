<?php

declare(strict_types=1);

namespace App\Core\Seeding;

use App\Core\Database;
use Database\Seeders\DatabaseSeeder;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;

final class SeederRunner
{
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly Database $db,
    ) {
    }

    /** @return list<class-string<Seeder>> */
    public function defaultSeeders(bool $withDemo): array
    {
        return $withDemo
            ? [...DatabaseSeeder::REFERENCE, ...DatabaseSeeder::DEMO]
            : DatabaseSeeder::REFERENCE;
    }

    /** @return class-string<Seeder> */
    public function resolve(string $name): string
    {
        $class = str_contains($name, '\\') ? $name : 'Database\\Seeders\\' . $name;
        if (!class_exists($class) || !is_subclass_of($class, Seeder::class)) {
            throw new InvalidArgumentException("Seeder introuvable : {$name}");
        }

        return $class;
    }

    /**
     * Chaque seeder s'exécute dans sa propre transaction.
     *
     * @param list<class-string<Seeder>> $classes
     * @param callable(string): void|null $onDone
     */
    public function run(array $classes, ?callable $onDone = null): void
    {
        foreach ($classes as $class) {
            /** @var Seeder $seeder */
            $seeder = $this->container->get($class);
            $this->db->transaction(static fn () => $seeder->run());
            if ($onDone !== null) {
                $onDone((new \ReflectionClass($class))->getShortName());
            }
        }
    }
}
