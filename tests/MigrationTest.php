<?php

declare(strict_types=1);

/**
 * Механизм миграций. Каждый тест берёт свою пустую базу в памяти: общая
 * тестовая база уже накатана, а тут проверяется как раз накат и откат.
 */

use Mailer\Storage\Database;
use Mailer\Storage\Migrator;

function migrationTestDb(): Database
{
    return new Database(['driver' => 'sqlite', 'sqlite' => ['path' => ':memory:']]);
}

test('накат заводит схему, повторный запуск ничего не делает', function (): void {
    $db       = migrationTestDb();
    $migrator = new Migrator($db);

    $applied = $migrator->run();

    assertTrue(count($applied) >= 10, 'миграции не применились');
    assertTrue($db->hasTable('messages'), 'нет таблицы писем');
    assertTrue($db->hasColumn('messages', 'sender_used'), 'нет колонки sender_used');
    assertSame([], (new Migrator($db))->run(), 'повторный накат что-то сделал');
    assertSame([], (new Migrator($db))->pending(), 'после наката остались невыполненные');
});

test('откат снимает последнюю пачку и её можно накатить снова', function (): void {
    $db = migrationTestDb();
    (new Migrator($db))->run();

    $rolledBack = (new Migrator($db))->rollback();

    assertTrue(count($rolledBack) >= 10, 'откат ничего не снял');
    assertFalse($db->hasTable('messages'), 'таблица писем осталась после отката');
    assertFalse($db->hasTable('roles'), 'таблица ролей осталась после отката');

    // down() должен быть честным: после отката всё встаёт обратно
    $again = (new Migrator($db))->run();

    assertTrue(count($again) >= 10, 'после отката миграции не применились заново');
    assertTrue($db->hasColumn('projects', 'unsubscribe'), 'колонка отписки не вернулась');
});

test('старые имена миграций переезжают на новые, а не применяются заново', function (): void {
    $db = migrationTestDb();
    (new Migrator($db))->run();

    // Так выглядит боевая база, накатанная до перехода на файлы с отметкой времени
    $db->execute("UPDATE migrations SET name = '0001_init' WHERE name = '20260818152758_init'");
    $db->execute("UPDATE migrations SET name = '0006_access' WHERE name = '20260820173127_access'");

    $applied = (new Migrator($db))->run();

    assertSame([], $applied, 'миграции пошли по второму разу');
    assertSame(
        1,
        (int) $db->value("SELECT COUNT(*) FROM migrations WHERE name = '20260818152758_init'"),
        'старое имя не переехало на новое'
    );
    assertSame(
        0,
        (int) $db->value("SELECT COUNT(*) FROM migrations WHERE name LIKE '00%'"),
        'в таблице остались старые имена'
    );
});

test('--pretend показывает запросы и не трогает базу', function (): void {
    $db   = migrationTestDb();
    $plan = (new Migrator($db))->pretend();

    assertTrue(count($plan) >= 10, 'план пустой');
    assertFalse($db->hasTable('messages'), 'pretend создал таблицы');

    $sql = implode(' ', $plan['20260818152758_init']);
    assertContains('CREATE TABLE IF NOT EXISTS messages', $sql);
});

test('миграция, которой нет в коде, видна отдельно', function (): void {
    $db = migrationTestDb();
    (new Migrator($db))->run();

    $db->insert('migrations', [
        'name'       => '20990101000000_from_future',
        'batch'      => 99,
        'applied_at' => Database::now(),
    ]);

    assertSame(['20990101000000_from_future'], (new Migrator($db))->unknown());
});

test('упавшая на середине миграция доезжает при повторном накате', function (): void {
    $db = migrationTestDb();
    (new Migrator($db))->run();

    // Так выглядит миграция, упавшая после части шагов: изменения в базе есть,
    // а записи о ней нет — в MySQL каждый ALTER коммитится сам по себе
    $db->execute("DELETE FROM migrations WHERE name = '20260821122257_webhook_subscriptions'");

    $applied = (new Migrator($db))->run();

    assertSame(['20260821122257_webhook_subscriptions'], $applied, 'миграция не доехала');
    assertTrue($db->hasColumn('webhook_deliveries', 'duration_ms'), 'колонка потерялась');
    assertSame([], (new Migrator($db))->pending(), 'после доката остались невыполненные');
});

test('занятая блокировка не пускает второй накат', function (): void {
    $db = migrationTestDb();

    $first = new Mailer\Storage\Lock($db, 'mailer_test_lock');
    assertTrue($first->acquire(1), 'первая блокировка не взялась');

    $second = new Mailer\Storage\Lock($db, 'mailer_test_lock');
    assertFalse($second->acquire(1), 'вторая блокировка взялась поверх первой');

    $first->release();
    assertTrue($second->acquire(1), 'после освобождения блокировка должна браться');
    $second->release();
});

test('блокировка держится между процессами', function (): void {
    if (!function_exists('proc_open')) {
        skipTest('proc_open выключен');
    }

    // В SQLite блокировка — это flock на файле в var/lock, и проверить её можно
    // только вторым процессом: внутри одного PHP держит её сам за себя.
    // MySQL с его GET_LOCK тестами не проверяется — они идут на SQLite.
    $marker  = (string) tempnam(sys_get_temp_dir(), 'lock-held-');
    $command = [PHP_BINARY, MAILER_ROOT . '/tests/lock-stub.php', 'mailer_test_lock', $marker];

    $pipes   = [];
    $process = @proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

    if (!is_resource($process)) {
        @unlink($marker);
        skipTest('не удалось запустить второй процесс');
    }

    // Первая строка печатается, когда блокировка уже взята
    $ready = trim((string) fgets($pipes[1]));

    assertSame('held', $ready, 'второй процесс не взял блокировку');

    $lock = new Mailer\Storage\Lock(migrationTestDb(), 'mailer_test_lock');
    assertFalse($lock->acquire(1), 'блокировку взяли поверх чужой');

    // Убираем метку — процесс отпускает блокировку и выходит
    @unlink($marker);
    $left = trim((string) fgets($pipes[1]));

    assertSame('released', $left, 'второй процесс не отпустил блокировку');

    foreach ($pipes as $pipe) {
        @fclose($pipe);
    }

    @proc_close($process);

    assertTrue($lock->acquire(2), 'после освобождения блокировка должна браться');
    $lock->release();
});

test('докат не переносит вебхуки проектов по второму разу', function (): void {
    $db = migrationTestDb();
    (new Migrator($db))->run();

    $now = Database::now();
    $db->insert('projects', [
        'name'           => 'проект-с-вебхуком',
        'api_key_prefix' => 'mgr',
        'api_key_hash'   => 'hash',
        'webhook_url'    => 'https://example.test/hook',
        'created_at'     => $now,
        'updated_at'     => $now,
    ]);

    // Перенос уже был выполнен — так выглядит база после первого прохода миграции
    $project = (int) $db->value("SELECT id FROM projects WHERE name = 'проект-с-вебхуком'");
    $db->insert('project_webhooks', [
        'project_id' => $project,
        'name'       => 'Вебхук проекта',
        'url'        => 'https://example.test/hook',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $db->execute("DELETE FROM migrations WHERE name = '20260821122257_webhook_subscriptions'");
    (new Migrator($db))->run();

    assertSame(
        1,
        (int) $db->value('SELECT COUNT(*) FROM project_webhooks WHERE project_id = :id', ['id' => $project]),
        'подписка задвоилась при докате'
    );
});
