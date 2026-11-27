<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use App\Core\Migrator;
use App\Core\Seeding\SeederRunner;
use PHPUnit\Framework\Attributes\Depends;

/**
 * Réversibilité des migrations et idempotence des seeders.
 * Ces tests modifient le schéma : ils n'utilisent pas la transaction automatique.
 */
final class MigrationsAndSeedersTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        $this->db = self::$container->get(Database::class);
    }

    protected function tearDown(): void
    {
    }

    public function testAllMigrationsCanBeRolledBackAndReapplied(): void
    {
        $migrator = self::$container->get(Migrator::class);
        $total = count($migrator->files());

        $migrator->dropAll();
        self::assertCount($total, $migrator->migrate());

        // Annule tout, lot par lot (un seul lot ici), puis réapplique.
        self::assertCount($total, $migrator->rollback(PHP_INT_MAX));
        self::assertSame(0, (int) $this->db->fetchValue(
            "SELECT count(*) FROM information_schema.tables WHERE table_schema = 'public' AND table_name <> 'schema_migrations'"
        ));

        self::assertCount($total, $migrator->migrate());
        self::assertSame([], $migrator->pending());
    }

    #[Depends('testAllMigrationsCanBeRolledBackAndReapplied')]
    public function testSeedersAreIdempotent(): void
    {
        $runner = self::$container->get(SeederRunner::class);
        $seeders = $runner->defaultSeeders(withDemo: true);

        $runner->run($seeders);
        $first = $this->counts();

        $runner->run($seeders);
        self::assertSame($first, $this->counts(), 'Relancer les seeders ne doit créer aucun doublon.');

        self::assertSame(1, $first['templates_actifs']);
        self::assertGreaterThan(0, $first['variant_prices']);
    }

    #[Depends('testSeedersAreIdempotent')]
    public function testSeedersDoNotOverwriteAdminChanges(): void
    {
        $this->db->execute("UPDATE site_settings SET value = '\"Mon slogan\"' WHERE key = 'general.tagline'");
        $this->db->execute("UPDATE booking_rules SET body = 'Règle modifiée' WHERE position = 0");

        self::$container->get(SeederRunner::class)->run(\Database\Seeders\DatabaseSeeder::REFERENCE);

        self::assertSame('"Mon slogan"', $this->db->fetchValue("SELECT value::text FROM site_settings WHERE key = 'general.tagline'"));
        self::assertSame('Règle modifiée', $this->db->fetchValue('SELECT body FROM booking_rules WHERE position = 0'));
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        DemoUploadsGuard::stash();
    }

    public static function tearDownAfterClass(): void
    {
        // Images d'exemple écrites par le seeder de démonstration ; celles du site local sont remises.
        DemoUploadsGuard::clearLive();
        DemoUploadsGuard::restore();

        // Remet le schéma de référence pour les classes de tests suivantes.
        self::rebuildSchema();
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        $counts = [];
        foreach (['roles', 'permissions', 'role_permissions', 'booking_statuses', 'service_sizes', 'service_lengths', 'option_groups', 'service_options', 'booking_rules', 'opening_hours', 'site_settings', 'templates', 'pages', 'page_sections', 'service_categories', 'services', 'service_variants', 'variant_prices', 'option_group_assignments'] as $table) {
            $counts[$table] = (int) $this->db->fetchValue("SELECT count(*) FROM {$table}");
        }
        $counts['templates_actifs'] = (int) $this->db->fetchValue('SELECT count(*) FROM templates WHERE is_active');

        return $counts;
    }
}
