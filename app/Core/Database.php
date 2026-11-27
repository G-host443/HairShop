<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOStatement;
use Throwable;

/**
 * Connexion PDO PostgreSQL (ouverte à la première requête) et helpers de requêtes préparées.
 */
final class Database
{
    private ?PDO $pdo = null;

    /**
     * @param array{host: string, port: int, database: string, username: string, password: string} $config
     */
    public function __construct(
        private readonly array $config,
        private readonly string $timezone = 'Europe/Paris',
    ) {
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $dsn = sprintf(
                'pgsql:host=%s;port=%d;dbname=%s;options=--client_encoding=UTF8',
                $this->config['host'],
                $this->config['port'],
                $this->config['database'],
            );

            $this->pdo = new PDO($dsn, $this->config['username'], $this->config['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ]);

            $this->pdo->exec('SET TIME ZONE ' . $this->pdo->quote($this->timezone));
            $this->pdo->exec("SET application_name = 'hairstyle-garance'");
        }

        return $this->pdo;
    }

    public function databaseName(): string
    {
        return $this->config['database'];
    }

    /** @param array<string|int, mixed> $params */
    public function query(string $sql, array $params = []): PDOStatement
    {
        $started = hrtime(true);
        $statement = $this->pdo()->prepare($sql);

        foreach ($params as $key => $value) {
            $name = is_int($key) ? $key + 1 : (str_starts_with($key, ':') ? $key : ':' . $key);
            $type = match (true) {
                is_int($value) => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                $value === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $statement->bindValue($name, $value, $type);
        }

        $statement->execute();
        $this->queryCount++;
        $this->queryNanoseconds += hrtime(true) - $started;
        if ($this->queryLog !== null) {
            $this->queryLog[] = preg_replace('/\s+/', ' ', trim($sql));
        }

        return $statement;
    }

    private int $queryCount = 0;
    private int $queryNanoseconds = 0;

    /** @var list<string>|null journal des requêtes (diagnostic uniquement, désactivé par défaut) */
    private ?array $queryLog = null;

    public function enableQueryLog(): void
    {
        $this->queryLog = [];
    }

    /** @return list<string> */
    public function queryLog(): array
    {
        return $this->queryLog ?? [];
    }

    /** @return array{queries: int, ms: float} requêtes exécutées depuis la création de l'objet (diagnostic) */
    public function stats(): array
    {
        return ['queries' => $this->queryCount, 'ms' => round($this->queryNanoseconds / 1e6, 1)];
    }

    /**
     * @param array<string|int, mixed> $params
     * @return list<array<string, mixed>>
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    /**
     * @param array<string|int, mixed> $params
     * @return array<string, mixed>|null
     */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    /** @param array<string|int, mixed> $params */
    public function fetchValue(string $sql, array $params = []): mixed
    {
        $value = $this->query($sql, $params)->fetchColumn();

        return $value === false ? null : $value;
    }

    /**
     * Exécute une requête et renvoie le nombre de lignes affectées.
     *
     * @param array<string|int, mixed> $params
     */
    public function execute(string $sql, array $params = []): int
    {
        return $this->query($sql, $params)->rowCount();
    }

    /**
     * Exécute du SQL brut pouvant contenir plusieurs instructions (migrations uniquement).
     */
    public function unprepared(string $sql): void
    {
        $this->pdo()->exec($sql);
    }

    private int $savepoints = 0;

    /**
     * Exécute $callback dans une transaction ; annulation automatique en cas d'exception.
     * Un appel imbriqué utilise un point de sauvegarde : en cas d'erreur, seule sa partie est annulée
     * et la transaction englobante reste utilisable.
     *
     * @template T
     * @param callable(self): T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $pdo = $this->pdo();

        if ($pdo->inTransaction()) {
            $name = 'sp_' . (++$this->savepoints);
            $pdo->exec("SAVEPOINT {$name}");
            try {
                $result = $callback($this);
                $pdo->exec("RELEASE SAVEPOINT {$name}");

                return $result;
            } catch (Throwable $e) {
                $pdo->exec("ROLLBACK TO SAVEPOINT {$name}");
                throw $e;
            } finally {
                $this->savepoints--;
            }
        }

        $pdo->beginTransaction();
        try {
            $result = $callback($this);
            $pdo->commit();

            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function disconnect(): void
    {
        $this->pdo = null;
    }
}
