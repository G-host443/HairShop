<?php

declare(strict_types=1);

namespace App\Core\Console;

use Psr\Container\ContainerInterface;
use Throwable;

final class Application
{
    /** @var array<string, class-string<Command>> */
    private array $commands = [];

    /** @param list<class-string<Command>> $commandClasses */
    public function __construct(
        private readonly ContainerInterface $container,
        array $commandClasses,
        private readonly Output $output = new Output(),
    ) {
        foreach ($commandClasses as $class) {
            $command = $this->container->get($class);
            $this->commands[$command->name()] = $class;
        }
    }

    /** @param list<string> $argv */
    public function run(array $argv): int
    {
        $name = $argv[1] ?? 'list';
        [$arguments, $options] = self::parseArguments(array_slice($argv, 2));

        if ($name === 'list' || $name === '--help' || $name === '-h') {
            $this->listCommands();

            return 0;
        }

        if (!isset($this->commands[$name])) {
            $this->output->error("Commande inconnue : {$name}");
            $this->listCommands();

            return 1;
        }

        /** @var Command $command */
        $command = $this->container->get($this->commands[$name]);
        $command->setOutput($this->output);

        try {
            return $command->handle($arguments, $options);
        } catch (Throwable $e) {
            $this->output->error($e->getMessage());
            if ($e->getPrevious() !== null) {
                $this->output->error('  ↳ ' . $e->getPrevious()->getMessage());
            }

            return 1;
        }
    }

    /**
     * @param list<string> $args
     * @return array{0: list<string>, 1: array<string, string|bool>}
     */
    public static function parseArguments(array $args): array
    {
        $arguments = [];
        $options = [];
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--')) {
                $parts = explode('=', substr($arg, 2), 2);
                $options[$parts[0]] = $parts[1] ?? true;
            } else {
                $arguments[] = $arg;
            }
        }

        return [$arguments, $options];
    }

    private function listCommands(): void
    {
        $this->output->line('Hair_style by Garance — console');
        $this->output->line();
        $this->output->line('Usage : php bin/console <commande> [arguments] [--options]');
        $this->output->line();

        $names = array_keys($this->commands);
        sort($names);
        $width = max(array_map('strlen', $names ?: ['']));
        foreach ($names as $name) {
            $description = $this->container->get($this->commands[$name])->description();
            $this->output->line('  ' . str_pad($name, $width + 2) . $this->output->muted($description));
        }
    }
}
