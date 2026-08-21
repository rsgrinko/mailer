<?php

declare(strict_types=1);

namespace Mailer\Storage\Schema;

use Mailer\Storage\Database;

/**
 * Исполнитель схемы: принимает описание таблицы и выполняет получившийся SQL.
 *
 * В режиме «на словах» (pretend) ничего не выполняется, а запросы копятся
 * в журнале — так работает `migrate --pretend` и так же миграции сверяются
 * тестами, не трогая базу.
 */
final class Builder
{
    private Database $db;
    private Types $types;
    private bool $pretend;

    /** @var array<int, string> */
    private array $log = [];

    /** @var array<int, string> Шаги, которые уже были выполнены раньше */
    private array $skipped = [];

    public function __construct(Database $db, bool $pretend = false)
    {
        $this->db      = $db;
        $this->types   = new Types($db);
        $this->pretend = $pretend;
    }

    public function db(): Database
    {
        return $this->db;
    }

    public function isSqlite(): bool
    {
        return $this->db->isSqlite();
    }

    /**
     * Создать таблицу.
     *
     * @param callable(Blueprint): void $definition
     */
    public function create(string $table, callable $definition): void
    {
        $blueprint = new Blueprint($table);
        $definition($blueprint);

        foreach ($blueprint->createOperations($this->types) as $operation) {
            $this->apply($operation);
        }
    }

    /**
     * Изменить существующую таблицу: добавить или убрать колонки и индексы.
     *
     * @param callable(Blueprint): void $definition
     */
    public function table(string $table, callable $definition): void
    {
        $blueprint = new Blueprint($table);
        $definition($blueprint);

        foreach ($blueprint->alterOperations($this->types) as $operation) {
            $this->apply($operation);
        }
    }

    /**
     * Удалить таблицу, если она есть. Нужно откатам: down() не должен падать
     * на таблице, которую предыдущий откат уже снял.
     */
    public function drop(string $table): void
    {
        $this->statement('DROP TABLE IF EXISTS ' . $table);
    }

    /**
     * Произвольный запрос: перенос данных, INSERT со стартовыми значениями,
     * специфичный для драйвера DDL.
     *
     * @param array<string, mixed> $params
     */
    public function statement(string $sql, array $params = []): void
    {
        $this->log[] = $sql;

        if (!$this->pretend) {
            $this->db->execute($sql, $params);
        }
    }

    /**
     * Шаги, пропущенные как уже выполненные: панель и консоль показывают их,
     * чтобы «миграция прошла, а половины запросов не было» не выглядело загадкой.
     *
     * @return array<int, string>
     */
    public function skipped(): array
    {
        return $this->skipped;
    }

    /**
     * Выполняет шаг схемы, если он ещё не выполнен.
     */
    private function apply(Operation $operation): void
    {
        if ($this->pretend) {
            $this->log[] = $operation->sql();

            return;
        }

        if (!$operation->needed($this->db)) {
            $this->skipped[] = $operation->sql();

            return;
        }

        $this->log[] = $operation->sql();
        $this->db->execute($operation->sql());
    }

    /**
     * Все запросы, прошедшие через билдер.
     *
     * @return array<int, string>
     */
    public function log(): array
    {
        return $this->log;
    }

}
