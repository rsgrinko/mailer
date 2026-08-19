<?php

declare(strict_types=1);

namespace Mailer\Storage;

use Mailer\Support\Config;
use PDO;
use PDOException;
use Throwable;

/**
 * Тонкая обёртка над PDO. Знает про два драйвера — sqlite и mysql — и прячет
 * различия между ними (автоинкремент, upsert, блокировка строк).
 */
final class Database
{
    public const SQLITE = 'sqlite';
    public const MYSQL  = 'mysql';

    private static ?self $instance = null;

    private PDO $pdo;
    private string $driver;

    /**
     * @param array<string, mixed>|null $config Настройки блока 'db'; по умолчанию берём из конфига
     */
    public function __construct(?array $config = null)
    {
        $config = $config ?? (array) Config::get('db', []);
        $driver = (string) ($config['driver'] ?? self::SQLITE);

        if (!in_array($driver, [self::SQLITE, self::MYSQL], true)) {
            throw new StorageException('Неизвестный драйвер базы данных: ' . $driver);
        }

        $this->driver = $driver;
        $this->pdo    = $driver === self::SQLITE
            ? $this->connectSqlite((array) ($config['sqlite'] ?? []))
            : $this->connectMysql((array) ($config['mysql'] ?? []));
    }

    /**
     * Общее подключение на весь процесс.
     */
    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Подменить подключение (используется тестами).
     */
    public static function setInstance(?self $database): void
    {
        self::$instance = $database;
    }

    private function connectSqlite(array $config): PDO
    {
        $path = (string) ($config['path'] ?? '');
        if ($path === '') {
            $path = MAILER_ROOT . '/var/mailer.sqlite';
        }

        if ($path !== ':memory:') {
            $dir = dirname($path);
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
        }

        try {
            $pdo = new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $e) {
            throw new StorageException('Не удалось открыть базу SQLite: ' . $e->getMessage(), [], 0, $e);
        }

        // WAL даёт нормальную параллельную работу воркера и веб-части
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 10000');

        return $pdo;
    }

    private function connectMysql(array $config): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            (string) ($config['host'] ?? '127.0.0.1'),
            (int) ($config['port'] ?? 3306),
            (string) ($config['database'] ?? 'mailer'),
            (string) ($config['charset'] ?? 'utf8mb4')
        );

        try {
            return new PDO($dsn, (string) ($config['username'] ?? ''), (string) ($config['password'] ?? ''), [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            throw new StorageException('Не удалось подключиться к MySQL: ' . $e->getMessage(), [], 0, $e);
        }
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function driver(): string
    {
        return $this->driver;
    }

    public function isSqlite(): bool
    {
        return $this->driver === self::SQLITE;
    }

    /**
     * Выполнить запрос и получить все строки.
     *
     * @param array<string|int, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function select(string $sql, array $params = []): array
    {
        $statement = $this->run($sql, $params);

        return $statement->fetchAll();
    }

    /**
     * Первая строка результата или null.
     *
     * @param array<string|int, mixed> $params
     * @return array<string, mixed>|null
     */
    public function selectOne(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Одно значение из первой строки.
     *
     * @param array<string|int, mixed> $params
     */
    public function value(string $sql, array $params = []): mixed
    {
        $row = $this->selectOne($sql, $params);
        if ($row === null) {
            return null;
        }

        return reset($row);
    }

    /**
     * Выполнить запрос без выборки. Возвращает количество затронутых строк.
     *
     * @param array<string|int, mixed> $params
     */
    public function execute(string $sql, array $params = []): int
    {
        return $this->run($sql, $params)->rowCount();
    }

    /**
     * Вставка строки. Возвращает id новой записи.
     *
     * @param array<string, mixed> $data
     */
    public function insert(string $table, array $data): int
    {
        $columns      = array_keys($data);
        $placeholders = array_map(static fn (string $column): string => ':' . $column, $columns);

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $columns),
            implode(', ', $placeholders)
        );

        $this->run($sql, $data);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Обновление строк по условию вида ['id' => 5].
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $where
     */
    public function update(string $table, array $data, array $where): int
    {
        $set        = [];
        $parameters = [];

        foreach ($data as $column => $value) {
            $set[]                    = $column . ' = :set_' . $column;
            $parameters['set_' . $column] = $value;
        }

        $conditions = [];
        foreach ($where as $column => $value) {
            $conditions[]                 = $column . ' = :where_' . $column;
            $parameters['where_' . $column] = $value;
        }

        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s',
            $table,
            implode(', ', $set),
            implode(' AND ', $conditions)
        );

        return $this->execute($sql, $parameters);
    }

    /**
     * Удаление строк по условию.
     *
     * @param array<string, mixed> $where
     */
    public function delete(string $table, array $where): int
    {
        $conditions = [];
        foreach (array_keys($where) as $column) {
            $conditions[] = $column . ' = :' . $column;
        }

        return $this->execute(
            sprintf('DELETE FROM %s WHERE %s', $table, implode(' AND ', $conditions)),
            $where
        );
    }

    /**
     * Выполняет замыкание в транзакции. При ошибке всё откатывается.
     *
     * @template T
     * @param callable(self): T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $this->pdo->beginTransaction();

        try {
            $result = $callback($this);
            $this->pdo->commit();

            return $result;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    /**
     * Страница результатов запроса: считает общее число строк и отдаёт нужный кусок.
     * Запрос передаётся без LIMIT, например 'SELECT * FROM projects ORDER BY name'.
     *
     * @param array<string|int, mixed> $params
     * @return array{items: array<int, array<string, mixed>>, total: int, page: int, pages: int, per_page: int}
     */
    public function page(string $sql, array $params = [], int $page = 1, int $perPage = 30): array
    {
        $perPage = max(1, min(200, $perPage));
        $total   = (int) $this->value('SELECT COUNT(*) FROM (' . $sql . ') AS page_source', $params);
        $pages   = max(1, (int) ceil($total / $perPage));
        $page    = max(1, min($page, $pages));

        $items = $this->select(
            $sql . ' LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage),
            $params
        );

        return [
            'items'    => $items,
            'total'    => $total,
            'page'     => $page,
            'pages'    => $pages,
            'per_page' => $perPage,
        ];
    }

    /**
     * Есть ли таблица в базе.
     */
    public function hasTable(string $table): bool
    {
        if ($this->isSqlite()) {
            $row = $this->selectOne(
                "SELECT name FROM sqlite_master WHERE type = 'table' AND name = :name",
                ['name' => $table]
            );

            return $row !== null;
        }

        $row = $this->selectOne(
            'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :name',
            ['name' => $table]
        );

        return $row !== null;
    }

    /**
     * Текущее время в формате, в котором мы храним даты.
     */
    public static function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    /**
     * Время со сдвигом в секундах.
     */
    public static function at(int $secondsFromNow): string
    {
        return date('Y-m-d H:i:s', time() + $secondsFromNow);
    }

    /**
     * @param array<string|int, mixed> $params
     */
    private function run(string $sql, array $params): \PDOStatement
    {
        try {
            $statement = $this->pdo->prepare($sql);

            foreach ($params as $key => $value) {
                $name = is_int($key) ? $key + 1 : ':' . ltrim((string) $key, ':');

                $type = match (true) {
                    is_int($value)  => PDO::PARAM_INT,
                    is_bool($value) => PDO::PARAM_INT,
                    $value === null => PDO::PARAM_NULL,
                    default         => PDO::PARAM_STR,
                };

                $statement->bindValue($name, is_bool($value) ? (int) $value : $value, $type);
            }

            $statement->execute();

            return $statement;
        } catch (PDOException $e) {
            throw new StorageException(
                'Ошибка запроса к базе: ' . $e->getMessage(),
                ['sql' => $sql],
                0,
                $e
            );
        }
    }
}
