<?php

declare(strict_types=1);

namespace Mailer\Storage\Schema;

use Mailer\Storage\Database;

/**
 * Один шаг миграции: запрос и условие, при котором его вообще нужно выполнять.
 *
 * Условие нужно ради повторного наката. MySQL коммитит каждый ALTER сам по себе,
 * поэтому упавшая на середине миграция оставляет половину изменений, а запись в
 * migrations не появляется. Следующий `migrate` пойдёт с её начала и без проверки
 * упрётся в «Duplicate column name» — база застревает и чинится руками. Шаг, который
 * уже сделан, просто пропускается.
 */
final class Operation
{
    private const TABLE  = 'table';
    private const COLUMN = 'column';
    private const INDEX  = 'index';

    private string $sql;
    private ?string $object;
    private string $table;
    private string $name;

    /** Выполнять, когда наличие объекта равно этому: false — ещё нет, true — уже есть */
    private bool $expect;

    private function __construct(string $sql, ?string $object = null, string $table = '', string $name = '', bool $expect = false)
    {
        $this->sql    = $sql;
        $this->object = $object;
        $this->table  = $table;
        $this->name   = $name;
        $this->expect = $expect;
    }

    /**
     * Запрос, который выполняется всегда: перенос данных, CREATE TABLE IF NOT EXISTS,
     * DROP TABLE IF EXISTS — всё, что идемпотентно само по себе.
     */
    public static function raw(string $sql): self
    {
        return new self($sql);
    }

    public static function addColumn(string $sql, string $table, string $column): self
    {
        return new self($sql, self::COLUMN, $table, $column, false);
    }

    public static function dropColumn(string $sql, string $table, string $column): self
    {
        return new self($sql, self::COLUMN, $table, $column, true);
    }

    public static function createIndex(string $sql, string $table, string $index): self
    {
        return new self($sql, self::INDEX, $table, $index, false);
    }

    public static function dropIndex(string $sql, string $table, string $index): self
    {
        return new self($sql, self::INDEX, $table, $index, true);
    }

    public static function createTable(string $sql, string $table): self
    {
        return new self($sql, self::TABLE, $table, $table, false);
    }

    public function sql(): string
    {
        return $this->sql;
    }

    /**
     * Нужно ли выполнять шаг: колонка ещё не добавлена, индекс ещё не построен и так далее.
     */
    public function needed(Database $db): bool
    {
        if ($this->object === null) {
            return true;
        }

        $exists = match ($this->object) {
            self::TABLE  => $db->hasTable($this->table),
            self::COLUMN => $db->hasColumn($this->table, $this->name),
            self::INDEX  => $db->hasIndex($this->table, $this->name),
        };

        return $exists === $this->expect;
    }
}
