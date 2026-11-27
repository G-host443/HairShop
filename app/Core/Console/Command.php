<?php

declare(strict_types=1);

namespace App\Core\Console;

/**
 * Commande de bin/console. Les dépendances sont injectées par le conteneur.
 */
abstract class Command
{
    protected Output $output;

    abstract public function name(): string;

    abstract public function description(): string;

    /**
     * @param list<string>          $arguments  arguments positionnels
     * @param array<string, string|bool> $options    --option=valeur ou --drapeau
     * @return int code de sortie (0 = succès)
     */
    abstract public function handle(array $arguments, array $options): int;

    public function setOutput(Output $output): void
    {
        $this->output = $output;
    }
}
