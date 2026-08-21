<?php

declare(strict_types=1);

namespace Mailer\Storage;

use Mailer\Storage\Schema\Blueprint;
use Mailer\Storage\Schema\Builder;

/**
 * Одна миграция — один файл в каталоге migrations/ с именем вида
 * `20260821122257_webhook_subscriptions.php` и классом `WebhookSubscriptions`.
 *
 * `up()` накатывает изменение, `down()` откатывает его. Имя файла — это и есть
 * имя миграции в таблице migrations, поэтому переименовывать файл нельзя:
 * миграция станет новой и применится второй раз.
 */
abstract class Migration
{
    protected Builder $schema;

    public function __construct(Builder $schema)
    {
        $this->schema = $schema;
    }

    /**
     * Что миграция делает.
     */
    abstract public function up(): void;

    /**
     * Как это отменить.
     */
    abstract public function down(): void;

    /**
     * @param callable(Blueprint): void $definition
     */
    protected function create(string $table, callable $definition): void
    {
        $this->schema->create($table, $definition);
    }

    /**
     * @param callable(Blueprint): void $definition
     */
    protected function table(string $table, callable $definition): void
    {
        $this->schema->table($table, $definition);
    }

    protected function drop(string $table): void
    {
        $this->schema->drop($table);
    }

    /**
     * @param array<string, mixed> $params
     */
    protected function statement(string $sql, array $params = []): void
    {
        $this->schema->statement($sql, $params);
    }

    protected function isSqlite(): bool
    {
        return $this->schema->isSqlite();
    }

    /**
     * Текущее время в том виде, в каком мы храним даты.
     */
    protected function now(): string
    {
        return Database::now();
    }
}
