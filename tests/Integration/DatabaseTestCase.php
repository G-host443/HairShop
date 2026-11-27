<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use App\Core\Migrator;
use App\Core\Seeding\SeederRunner;
use DI\Container;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Base des tests d'intégration : base hairstyle_garance_test (DB_TEST_DATABASE).
 *
 * Le schéma est reconstruit une fois par exécution (migrate:fresh + données de référence),
 * puis chaque test s'exécute dans une transaction annulée à la fin.
 */
abstract class DatabaseTestCase extends TestCase
{
    protected static ?Container $container = null;
    private static bool $schemaReady = false;

    protected Database $db;

    public static function setUpBeforeClass(): void
    {
        self::$container ??= require dirname(__DIR__, 2) . '/bootstrap/app.php';

        if (!self::$schemaReady) {
            self::rebuildSchema();
            self::$schemaReady = true;
        }
    }

    protected static function rebuildSchema(): void
    {
        $db = self::$container->get(Database::class);
        if (!str_ends_with($db->databaseName(), '_test')) {
            self::fail("Refus d'exécuter les tests sur une base qui ne se termine pas par « _test » : {$db->databaseName()}");
        }

        $migrator = self::$container->get(Migrator::class);
        $migrator->dropAll();
        $migrator->migrate();

        $runner = self::$container->get(SeederRunner::class);
        $runner->run($runner->defaultSeeders(withDemo: false));
    }

    protected function setUp(): void
    {
        $this->db = self::$container->get(Database::class);
        $this->db->pdo()->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    /**
     * Vérifie qu'une requête est rejetée par PostgreSQL avec le SQLSTATE attendu,
     * sans interrompre la transaction du test (point de sauvegarde).
     *
     * @param array<string|int, mixed> $params
     */
    protected function assertSqlFails(string $sqlState, string $sql, array $params = [], string $message = ''): void
    {
        $pdo = $this->db->pdo();
        $pdo->exec('SAVEPOINT assert_sql_fails');

        try {
            $this->db->execute($sql, $params);
        } catch (PDOException $e) {
            $pdo->exec('ROLLBACK TO SAVEPOINT assert_sql_fails');
            self::assertSame($sqlState, $e->getCode(), $message !== '' ? $message : $e->getMessage());

            return;
        }

        $pdo->exec('ROLLBACK TO SAVEPOINT assert_sql_fails');
        self::fail($message !== '' ? $message : "La requête aurait dû échouer avec SQLSTATE {$sqlState}.");
    }

    // ---- Fabriques de données minimales ----

    protected function createService(string $slug = 'test-service'): int
    {
        $categoryId = (int) $this->db->fetchValue(
            "INSERT INTO service_categories (slug, name) VALUES (:slug, 'Catégorie test') RETURNING id",
            ['slug' => $slug . '-cat'],
        );

        return (int) $this->db->fetchValue(
            "INSERT INTO services (slug, category_id, name, pricing_mode, price_kind, price, duration_minutes)
             VALUES (:slug, :category, 'Prestation test', 'simple', 'fixed', 50, 120) RETURNING id",
            ['slug' => $slug, 'category' => $categoryId],
        );
    }

    protected function createCustomer(string $email = 'cliente@example.test'): int
    {
        return (int) $this->db->fetchValue(
            "INSERT INTO customers (first_name, last_name, email) VALUES ('Awa', 'Test', :email) RETURNING id",
            ['email' => $email],
        );
    }
}
