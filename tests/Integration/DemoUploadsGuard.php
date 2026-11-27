<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Le seeder de démonstration écrit ses images dans public/uploads/demo, dossier partagé avec le site de développement.
 * Les tests qui le lancent mettent de côté les images existantes avant de commencer et les remettent à la fin,
 * pour ne jamais effacer celles du site local.
 */
final class DemoUploadsGuard
{
    private static function live(): string
    {
        return public_path('uploads/demo');
    }

    private static function backup(): string
    {
        return storage_path('testing/uploads-demo-backup');
    }

    /** À appeler dans setUpBeforeClass. */
    public static function stash(): void
    {
        // Sauvegarde laissée par une exécution interrompue : on la remet d'abord en place.
        self::restore();
        if (is_dir(self::live())) {
            @mkdir(dirname(self::backup()), 0775, true);
            rename(self::live(), self::backup());
        }
    }

    /** À appeler dans tearDownAfterClass. */
    public static function restore(): void
    {
        if (!is_dir(self::backup())) {
            return;
        }
        self::clearLive();
        rename(self::backup(), self::live());
    }

    /** Supprime les images écrites par les tests. */
    public static function clearLive(): void
    {
        foreach (glob(self::live() . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir(self::live());
    }
}
