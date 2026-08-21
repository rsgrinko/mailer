<?php

declare(strict_types=1);

namespace Mailer\Storage;

use Mailer\Storage\Schema\Blueprint;
use Mailer\Storage\Schema\Builder;
use Throwable;

/**
 * Миграции. Каждая — свой файл в каталоге migrations/ у корня проекта:
 * `20260821122257_webhook_subscriptions.php` с классом `WebhookSubscriptions`.
 * Файлы подхватываются сами, реестр править не нужно, порядок — по имени файла,
 * то есть по времени создания.
 *
 * Применённые запоминаются в таблице migrations вместе с номером пачки:
 * `migrate` кладёт всё применённое за раз в одну пачку, `migrate:rollback`
 * откатывает последнюю пачку целиком.
 */
final class Migrator
{
    /**
     * Имена первых десяти миграций до перехода на файлы с отметкой времени.
     * На боевой базе они уже применены — переименовываем записи, иначе миграции
     * пойдут по второму разу и упадут на существующих таблицах.
     */
    /** Имя общей блокировки и сколько ждать её освобождения */
    private const LOCK_NAME    = 'mailer_migrations';
    private const LOCK_TIMEOUT = 60;

    private const LEGACY_NAMES = [
        '0001_init'                  => '20260818152758_init',
        '0002_users'                 => '20260819160402_users',
        '0003_message_indexes'       => '20260819220045_message_indexes',
        '0004_message_fulltext'      => '20260819225022_message_fulltext',
        '0005_message_sender'        => '20260820162214_message_sender',
        '0006_access'                => '20260820173127_access',
        '0007_audit'                 => '20260820232541_audit',
        '0008_suppressions'          => '20260820232542_suppressions',
        '0009_unsubscribe'           => '20260820232543_unsubscribe',
        '0010_webhook_subscriptions' => '20260821122257_webhook_subscriptions',
    ];

    private Database $db;
    private string $path;
    private ?Builder $builder = null;

    /** @var array<string, Migration>|null */
    private ?array $migrations = null;

    public function __construct(?Database $db = null, ?string $path = null)
    {
        $this->db   = $db ?? Database::instance();
        $this->path = $path ?? MAILER_ROOT . '/migrations';
    }

    /**
     * Применяет все новые миграции. Возвращает список применённых имён.
     *
     * @return array<int, string>
     */
    public function run(): array
    {
        return $this->locked(function (): array {
            $this->prepare();

            $pending = $this->pending();
            if ($pending === []) {
                return [];
            }

            $batch   = $this->nextBatch();
            $applied = [];

            foreach ($pending as $name) {
                $migration = $this->migrations()[$name];

                $this->guard($name, function () use ($migration, $name, $batch) {
                    // В SQLite DDL транзакционен, и упавшая миграция откатится целиком.
                    // MySQL на каждом ALTER делает неявный коммит — там транзакция не спасёт,
                    // зато записи о наполовину применённой миграции в migrations не появится,
                    // и следующий накат повторит её шаги, пропустив уже сделанные.
                    $this->db->transaction(function () use ($migration, $name, $batch) {
                        $migration->up();

                        $this->db->insert('migrations', [
                            'name'       => $name,
                            'batch'      => $batch,
                            'applied_at' => Database::now(),
                        ]);
                    });
                });

                $applied[] = $name;
            }

            return $applied;
        });
    }

    /**
     * Откатывает последние пачки миграций. Возвращает список откаченных имён
     * в порядке отката — начиная с самой поздней.
     *
     * @return array<int, string>
     */
    public function rollback(int $batches = 1): array
    {
        if (!$this->db->hasTable('migrations')) {
            return [];
        }

        return $this->locked(function () use ($batches): array {
            $this->prepare();

            $names = $this->lastBatches(max(1, $batches));
            if ($names === []) {
                return [];
            }

            $known      = $this->migrations();
            $rolledBack = [];

            foreach ($names as $name) {
                if (!isset($known[$name])) {
                    throw new StorageException(
                        'Миграция ' . $name . ' есть в базе, но не в коде — откатить её нечем.'
                    );
                }

                $migration = $known[$name];

                $this->guard($name, function () use ($migration, $name) {
                    $this->db->transaction(function () use ($migration, $name) {
                        $migration->down();
                        $this->db->execute('DELETE FROM migrations WHERE name = :name', ['name' => $name]);
                    });
                });

                $rolledBack[] = $name;
            }

            return $rolledBack;
        });
    }

    /**
     * Что сделают новые миграции, если их применить: имя -> список запросов.
     * Ничего не выполняет — это `migrate --pretend`.
     *
     * @return array<string, array<int, string>>
     */
    public function pretend(): array
    {
        $plan = [];

        foreach ($this->pending() as $name) {
            $builder = new Builder($this->db, true);
            $class   = get_class($this->migrations()[$name]);

            /** @var Migration $migration */
            $migration = new $class($builder);
            $migration->up();

            $plan[$name] = $builder->log();
        }

        return $plan;
    }

    /**
     * Список ещё не применённых миграций.
     *
     * @return array<int, string>
     */
    public function pending(): array
    {
        $applied = $this->applied();

        return array_values(array_diff(array_keys($this->migrations()), $applied));
    }

    /**
     * Имена применённых миграций в порядке применения.
     *
     * @return array<int, string>
     */
    public function applied(): array
    {
        if (!$this->db->hasTable('migrations')) {
            return [];
        }

        $rows = $this->db->select('SELECT name FROM migrations ORDER BY name');

        return array_map(static fn (array $row): string => (string) $row['name'], $rows);
    }

    /**
     * Шаги последнего наката, пропущенные как уже выполненные. Так выглядит докат
     * миграции, упавшей на середине: часть её изменений в базе уже была.
     *
     * @return array<int, string>
     */
    public function skipped(): array
    {
        return $this->builder === null ? [] : $this->builder->skipped();
    }

    /**
     * Миграции, которые есть в базе, но которых нет в коде: обычно это откат
     * релиза. Молча игнорировать такое нельзя — панель показывает расхождение.
     *
     * @return array<int, string>
     */
    public function unknown(): array
    {
        return array_values(array_diff($this->applied(), array_keys($this->migrations())));
    }

    /**
     * Все миграции сервиса: имя файла без расширения -> объект.
     *
     * @return array<string, Migration>
     */
    public function migrations(): array
    {
        if ($this->migrations !== null) {
            return $this->migrations;
        }

        $builder = $this->builder = new Builder($this->db);
        $found   = [];

        foreach ((array) glob($this->path . '/*.php') as $file) {
            $file = (string) $file;
            $name = basename($file, '.php');

            if (preg_match('/^(\d{14})_([a-z0-9_]+)$/', $name, $matches) !== 1) {
                throw new StorageException(
                    'Файл миграции ' . $name . ' назван не по правилу 20260821122257_имя_миграции.php'
                );
            }

            $class = $this->className($matches[2]);

            require_once $file;

            if (!class_exists($class) || !is_subclass_of($class, Migration::class)) {
                throw new StorageException(
                    'В файле миграции ' . $name . ' нет класса ' . $class . ', унаследованного от Migration.'
                );
            }

            $found[$name] = new $class($builder);
        }

        ksort($found);

        return $this->migrations = $found;
    }

    /**
     * Класс миграции по хвосту имени файла: message_indexes -> MessageIndexes.
     */
    private function className(string $slug): string
    {
        return 'Mailer\\Migrations\\' . str_replace(' ', '', ucwords(str_replace('_', ' ', $slug)));
    }

    /**
     * Выполняет работу с базой под общей блокировкой: два наката разом — это
     * дважды применённая миграция, а на проде обычно ещё и упавший деплой.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    private function locked(callable $work): mixed
    {
        $lock = new Lock($this->db, self::LOCK_NAME);

        if (!$lock->acquire(self::LOCK_TIMEOUT)) {
            throw new StorageException(
                'Миграции уже накатывает другой процесс. Дождитесь его и повторите.'
            );
        }

        try {
            return $work();
        } finally {
            $lock->release();
        }
    }

    /**
     * Выполняет шаг миграции, дополняя любую ошибку её именем: без этого на проде
     * приходится гадать, на чём именно встал накат.
     */
    private function guard(string $name, callable $step): void
    {
        try {
            $step();
        } catch (Throwable $e) {
            throw new StorageException(
                'Миграция ' . $name . ' не выполнена: ' . $e->getMessage(),
                [],
                0,
                $e
            );
        }
    }

    /**
     * Имена миграций последних пачек, в порядке отката.
     *
     * @return array<int, string>
     */
    private function lastBatches(int $batches): array
    {
        $rows = $this->db->select(
            'SELECT DISTINCT batch FROM migrations ORDER BY batch DESC LIMIT ' . $batches
        );

        if ($rows === []) {
            return [];
        }

        $lowest = (int) $rows[count($rows) - 1]['batch'];

        $names = $this->db->select(
            'SELECT name FROM migrations WHERE batch >= :batch ORDER BY name DESC',
            ['batch' => $lowest]
        );

        return array_map(static fn (array $row): string => (string) $row['name'], $names);
    }

    private function nextBatch(): int
    {
        return (int) $this->db->value('SELECT MAX(batch) FROM migrations') + 1;
    }

    /**
     * Служебная таблица в актуальном виде: создать, если её нет, дополнить,
     * если она осталась от прежних версий.
     */
    private function prepare(): void
    {
        $schema = new Builder($this->db);

        if (!$this->db->hasTable('migrations')) {
            $schema->create('migrations', function (Blueprint $table) {
                $table->string('name')->primary();
                $table->integer('batch')->default(0);
                $table->dateTime('applied_at');
            });

            return;
        }

        // База, накатанная до появления пачек: колонки batch там ещё нет
        if (!$this->db->hasColumn('migrations', 'batch')) {
            $schema->table('migrations', function (Blueprint $table) {
                $table->integer('batch')->default(0);
            });
        }

        $this->renameLegacy();
    }

    /**
     * Переводит записи о старых миграциях (0001_init) на новые имена файлов.
     * Выполняется один раз: после переименования старых имён в таблице нет.
     */
    private function renameLegacy(): void
    {
        $applied = $this->applied();

        foreach (self::LEGACY_NAMES as $old => $new) {
            if (!in_array($old, $applied, true)) {
                continue;
            }

            if (in_array($new, $applied, true)) {
                // Обе записи разом — новое имя уже есть, старому взяться неоткуда
                $this->db->execute('DELETE FROM migrations WHERE name = :name', ['name' => $old]);
                continue;
            }

            $this->db->execute(
                'UPDATE migrations SET name = :new WHERE name = :old',
                ['new' => $new, 'old' => $old]
            );
        }
    }
}
