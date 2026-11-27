<?php

declare(strict_types=1);

namespace App\Core\Console\Commands;

use App\Core\Console\Command;

final class KeyGenerateCommand extends Command
{
    public function name(): string
    {
        return 'key:generate';
    }

    public function description(): string
    {
        return 'Génère APP_KEY dans le fichier .env (--show pour seulement l\'afficher)';
    }

    public function handle(array $arguments, array $options): int
    {
        $key = bin2hex(random_bytes(32));

        if (isset($options['show'])) {
            $this->output->line($key);

            return 0;
        }

        $envFile = base_path('.env');
        if (!is_file($envFile)) {
            $this->output->error('Fichier .env introuvable : copiez d\'abord .env.example en .env.');

            return 1;
        }

        $content = (string) file_get_contents($envFile);
        if (preg_match('/^APP_KEY=.+$/m', $content) && !isset($options['force'])) {
            $this->output->warn('APP_KEY est déjà définie. Utilisez --force pour la remplacer (les sessions et jetons existants deviendront invalides).');

            return 1;
        }

        $content = preg_match('/^APP_KEY=.*$/m', $content)
            ? (string) preg_replace('/^APP_KEY=.*$/m', 'APP_KEY=' . $key, $content)
            : rtrim($content) . PHP_EOL . 'APP_KEY=' . $key . PHP_EOL;

        file_put_contents($envFile, $content);
        $this->output->info('APP_KEY générée.');

        return 0;
    }
}
