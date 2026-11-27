<?php

declare(strict_types=1);

namespace App\Core\Console\Commands;

use App\Core\Console\Command;

/**
 * Vide les caches générés : conteneur compilé (production), gabarits Twig compilés, paramètres du site,
 * CSS des jetons de design. Tous se reconstruisent automatiquement à la requête suivante.
 *
 * À lancer après chaque mise à jour du code en production (sinon le conteneur compilé peut être périmé).
 */
final class CacheClearCommand extends Command
{
    public function name(): string
    {
        return 'cache:clear';
    }

    public function description(): string
    {
        return 'Vide les caches (conteneur, gabarits, paramètres, CSS des thèmes) — après chaque mise à jour';
    }

    public function handle(array $arguments, array $options): int
    {
        $removed = 0;
        foreach ([storage_path('cache/di'), storage_path('cache/twig')] as $directory) {
            $removed += self::emptyDirectory($directory);
        }
        foreach (array_merge(glob(storage_path('cache/settings-*.php')) ?: [], glob(public_path('cache/theme-*.css')) ?: []) as $file) {
            if (is_file($file) && unlink($file)) {
                $removed++;
            }
        }

        $this->output->line("{$removed} fichier(s) de cache supprimé(s).");

        return 0;
    }

    private static function emptyDirectory(string $directory): int
    {
        if (!is_dir($directory)) {
            return 0;
        }
        $count = 0;
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } elseif (@unlink($item->getPathname())) {
                $count++;
            }
        }

        return $count;
    }
}
