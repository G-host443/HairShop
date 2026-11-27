<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Règles métier garanties par le schéma (contraintes CHECK, EXCLUDE, UNIQUE).
 */
final class SchemaConstraintsTest extends DatabaseTestCase
{
    private const CHECK_VIOLATION = '23514';
    private const UNIQUE_VIOLATION = '23505';
    private const EXCLUSION_VIOLATION = '23P01';

    public function testSchemaHasAllTables(): void
    {
        $count = (int) $this->db->fetchValue(
            "SELECT count(*) FROM information_schema.tables
             WHERE table_schema = 'public' AND table_type = 'BASE TABLE' AND table_name <> 'schema_migrations'"
        );

        self::assertSame(49, $count, '48 tables du schéma initial + site_visits (étape 13).');
    }

    public function testSimpleServiceRequiresAPriceUnlessQuote(): void
    {
        $categoryId = (int) $this->db->fetchValue("INSERT INTO service_categories (slug, name) VALUES ('cat-prix', 'Cat') RETURNING id");

        $this->assertSqlFails(
            self::CHECK_VIOLATION,
            "INSERT INTO services (slug, category_id, name, pricing_mode, price_kind, price, duration_minutes)
             VALUES ('sans-prix', :c, 'Sans prix', 'simple', 'fixed', NULL, 60)",
            ['c' => $categoryId],
        );

        $this->db->execute(
            "INSERT INTO services (slug, category_id, name, pricing_mode, price_kind, price, duration_minutes)
             VALUES ('sur-devis', :c, 'Sur devis', 'simple', 'quote', NULL, 60)",
            ['c' => $categoryId],
        );
        self::assertSame(1, (int) $this->db->fetchValue("SELECT count(*) FROM services WHERE slug = 'sur-devis'"));
    }

    public function testGridCellPriceMustMatchItsKind(): void
    {
        $serviceId = $this->createService('grille');
        $variantId = (int) $this->db->fetchValue(
            "INSERT INTO service_variants (service_id, size_id) SELECT :s, id FROM service_sizes WHERE code = 'M' RETURNING id",
            ['s' => $serviceId],
        );
        $lengthId = (int) $this->db->fetchValue("SELECT id FROM service_lengths WHERE slug = 'classique'");

        $this->assertSqlFails(
            self::CHECK_VIOLATION,
            "INSERT INTO variant_prices (variant_id, length_id, price_kind, price) VALUES (:v, :l, 'quote', 120)",
            ['v' => $variantId, 'l' => $lengthId],
            'Une cellule « sur devis » ne doit pas avoir de prix.',
        );
        $this->assertSqlFails(
            self::CHECK_VIOLATION,
            "INSERT INTO variant_prices (variant_id, length_id, price_kind, price) VALUES (:v, :l, 'fixed', NULL)",
            ['v' => $variantId, 'l' => $lengthId],
            'Une cellule à prix fixe doit avoir un prix.',
        );
    }

    public function testOptionGroupAssignmentTargetsExactlyOneEntity(): void
    {
        $serviceId = $this->createService('cible');
        $categoryId = (int) $this->db->fetchValue('SELECT category_id FROM services WHERE id = :id', ['id' => $serviceId]);
        $groupId = (int) $this->db->fetchValue("SELECT id FROM option_groups WHERE code = 'finish'");

        $this->assertSqlFails(
            self::CHECK_VIOLATION,
            'INSERT INTO option_group_assignments (option_group_id, category_id, service_id) VALUES (:g, :c, :s)',
            ['g' => $groupId, 'c' => $categoryId, 's' => $serviceId],
        );
        $this->assertSqlFails(
            self::CHECK_VIOLATION,
            'INSERT INTO option_group_assignments (option_group_id) VALUES (:g)',
            ['g' => $groupId],
        );
    }

    public function testOpeningHoursCannotOverlapOnTheSameDay(): void
    {
        // Lundi 10:00-19:00 est inséré par OpeningHoursSeeder.
        $this->assertSqlFails(
            self::EXCLUSION_VIOLATION,
            "INSERT INTO opening_hours (weekday, opens_at, closes_at) VALUES (1, '18:00', '20:00')",
        );

        // Mercredi est fermé : une plage avec pause est acceptée.
        $this->db->execute("INSERT INTO opening_hours (weekday, opens_at, closes_at) VALUES (3, '09:00', '12:00'), (3, '13:00', '17:00')");
        self::assertSame(2, (int) $this->db->fetchValue('SELECT count(*) FROM opening_hours WHERE weekday = 3'));
    }

    public function testOnlyOneActiveTemplateAndOneHomePage(): void
    {
        $this->assertSqlFails(self::UNIQUE_VIOLATION, "UPDATE templates SET is_active = true WHERE slug = 'beauty-chic'");
        $this->assertSqlFails(self::UNIQUE_VIOLATION, "INSERT INTO pages (slug, title, kind) VALUES ('accueil-2', 'Accueil 2', 'home')");
    }

    public function testEmailsAreCaseInsensitivelyUnique(): void
    {
        $this->db->execute("INSERT INTO users (email, password_hash, first_name, last_name) VALUES ('Garance@Example.test', 'x', 'G', 'A')");

        $this->assertSqlFails(
            self::UNIQUE_VIOLATION,
            "INSERT INTO users (email, password_hash, first_name, last_name) VALUES ('garance@example.test', 'x', 'G', 'A')",
        );
    }

    public function testReviewRatingAndFeaturedRules(): void
    {
        $this->assertSqlFails(self::CHECK_VIOLATION, "INSERT INTO reviews (author_name, rating, comment) VALUES ('A', 6, 'Top')");
        $this->assertSqlFails(
            self::CHECK_VIOLATION,
            "INSERT INTO reviews (author_name, rating, comment, is_featured) VALUES ('A', 5, 'Top', true)",
            [],
            'Un avis non approuvé ne peut pas être mis en avant.',
        );
    }

    public function testCustomerNeedsAtLeastOneContact(): void
    {
        $this->assertSqlFails(self::CHECK_VIOLATION, "INSERT INTO customers (first_name, last_name) VALUES ('A', 'B')");
    }
}
