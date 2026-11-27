<?php

declare(strict_types=1);

namespace App\Core\Console\Commands;

use App\Core\Config;
use App\Core\Console\Command;
use App\Core\Database;
use App\Core\Migrator;
use Throwable;

/**
 * Diagnostic de l'environnement : version PHP, extensions, dossiers, connexion PostgreSQL.
 */
final class AppCheckCommand extends Command
{
    private const EXTENSIONS = ['pdo_pgsql', 'mbstring', 'intl', 'fileinfo', 'gd', 'sodium', 'openssl', 'dom'];

    public function __construct(
        private readonly Config $config,
        private readonly Database $db,
        private readonly Migrator $migrator,
    ) {
    }

    public function name(): string
    {
        return 'app:check';
    }

    public function description(): string
    {
        return 'Vérifie l\'environnement (PHP, extensions, dossiers, base de données)';
    }

    public function handle(array $arguments, array $options): int
    {
        $ok = true;
        $check = function (string $label, bool $passed, string $detail = '') use (&$ok): void {
            $ok = $ok && $passed;
            $line = ($passed ? '  ✔ ' : '  ✘ ') . $label . ($detail !== '' ? ' — ' . $detail : '');
            $passed ? $this->output->info($line) : $this->output->error($line);
        };

        $this->output->line('PHP');
        // 8.4 : analyseur HTML5 natif (Dom\HTMLDocument) utilisé pour nettoyer le contenu du blog.
        $check('Version ≥ 8.4', version_compare(PHP_VERSION, '8.4.0', '>='), PHP_VERSION);
        foreach (self::EXTENSIONS as $extension) {
            $check("Extension {$extension}", extension_loaded($extension));
        }

        $this->output->line();
        $this->output->line('Configuration');
        $check('Fichier .env', is_file(base_path('.env')));
        $check('APP_KEY définie', strlen((string) $this->config->get('app.key')) >= 32);

        $this->output->line();
        $this->output->line('Dossiers accessibles en écriture');
        foreach (['storage/logs', 'storage/cache', 'storage/sessions', 'public/uploads', 'public/cache', 'public/themes'] as $dir) {
            $check($dir, is_dir(base_path($dir)) && is_writable(base_path($dir)));
        }

        $this->output->line();
        $this->output->line('PostgreSQL');
        try {
            $version = (string) $this->db->fetchValue('SHOW server_version');
            $check('Connexion à « ' . $this->db->databaseName() . ' »', true, 'PostgreSQL ' . $version);
            $check('Version ≥ 15', (int) $version >= 15);

            $available = array_column($this->db->fetchAll(
                "SELECT name FROM pg_available_extensions WHERE name IN ('btree_gist', 'citext')"
            ), 'name');
            $check('Extension btree_gist disponible', in_array('btree_gist', $available, true));
            $check('Extension citext disponible', in_array('citext', $available, true));

            $pending = $this->migrator->pending();
            $check('Migrations à jour', $pending === [], $pending === [] ? '' : count($pending) . ' en attente');
        } catch (Throwable $e) {
            $check('Connexion à « ' . $this->db->databaseName() . ' »', false, $e->getMessage());
        }

        $this->output->line();
        $ok ? $this->output->info('Environnement prêt.') : $this->output->warn('Des points sont à corriger.');

        return $ok ? 0 : 1;
    }
}
