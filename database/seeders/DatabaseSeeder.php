<?php

declare(strict_types=1);

namespace Database\Seeders;

/**
 * Ordre d'exécution des seeders.
 *
 * - REFERENCE : données indispensables au fonctionnement (rôles, statuts, réglages…).
 * - DEMO : contenu de démonstration (catalogue aux prix fictifs, galerie, blog…), via « db:seed --demo » ;
 *   le contenu d'exemple (hors catalogue) se supprime avec « demo:clear ».
 *
 * Tous les seeders sont idempotents et ne remplacent pas les données modifiées dans l'administration.
 */
final class DatabaseSeeder
{
    public const REFERENCE = [
        RolesAndPermissionsSeeder::class,
        BookingStatusesSeeder::class,
        SizesAndLengthsSeeder::class,
        OptionGroupsSeeder::class,
        BookingRulesSeeder::class,
        OpeningHoursSeeder::class,
        SiteSettingsSeeder::class,
        TemplatesSeeder::class,
        PagesSeeder::class,
    ];

    public const DEMO = [
        CatalogDemoSeeder::class,
        DemoContentSeeder::class,
    ];
}
