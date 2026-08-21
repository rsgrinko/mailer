<?php

declare(strict_types=1);

/**
 * Схема против кода: код может приехать раньше миграции, и тогда сервис падает
 * на первом же обращении к несуществующей колонке. Здесь по исходникам собирается
 * список таблиц и колонок, в которые код пишет, и сверяется с накатанной схемой.
 */

use Mailer\Storage\Database;

/**
 * Записи вида «таблица → колонки», которые код пишет через Database::insert()/update().
 *
 * Читаем токенами, а не регуляркой: в запросах хватает скобок и стрелок, а нам
 * нужны именно литеральные массивы у вызовов с литеральным именем таблицы.
 *
 * @return array<int, array{table: string, column: string, file: string, line: int}>
 */
function schemaWrites(): array
{
    static $writes = null;

    if ($writes !== null) {
        return $writes;
    }

    $writes = [];

    foreach (projectPhpFiles() as $file) {
        if (!str_contains($file, '/src/') && !str_contains($file, '/migrations/')) {
            continue;
        }

        $tokens = token_get_all((string) file_get_contents($file));
        $count  = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (!is_array($token) || $token[0] !== T_STRING || !in_array($token[1], ['insert', 'update'], true)) {
                continue;
            }

            // Дальше должно быть ('таблица', [ … ]
            if (($tokens[$i + 1] ?? '') !== '(') {
                continue;
            }

            $table = $tokens[$i + 2] ?? null;

            if (!is_array($table) || $table[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            $name = trim($table[1], "'\"");

            if (($tokens[$i + 3] ?? '') !== ',') {
                continue;
            }

            // Пропускаем пробелы до массива
            $j = $i + 4;

            while (is_array($tokens[$j] ?? null) && $tokens[$j][0] === T_WHITESPACE) {
                $j++;
            }

            if (($tokens[$j] ?? '') !== '[') {
                continue;
            }

            $depth = 0;

            for ($k = $j; $k < $count; $k++) {
                $current = $tokens[$k];

                if ($current === '[') {
                    $depth++;

                    continue;
                }

                if ($current === ']') {
                    $depth--;

                    if ($depth === 0) {
                        break;
                    }

                    continue;
                }

                if ($depth !== 1 || !is_array($current) || $current[0] !== T_CONSTANT_ENCAPSED_STRING) {
                    continue;
                }

                // Ключ массива — строка, за которой идёт стрелка
                $next = $k + 1;

                while (is_array($tokens[$next] ?? null) && $tokens[$next][0] === T_WHITESPACE) {
                    $next++;
                }

                if (!is_array($tokens[$next] ?? null) || $tokens[$next][0] !== T_DOUBLE_ARROW) {
                    continue;
                }

                $writes[] = [
                    'table'  => $name,
                    'column' => trim($current[1], "'\""),
                    'file'   => shortPath($file),
                    'line'   => (int) $current[2],
                ];
            }
        }
    }

    return $writes;
}

test('код пишет только в те колонки, что есть в схеме', function (): void {
    $db       = Database::instance();
    $problems = [];
    $checked  = 0;

    foreach (schemaWrites() as $write) {
        // Миграции заводят схему сами и пишут в неё же по ходу дела — с ними
        // сверяться нечем: колонка появляется в этой же миграции
        if (str_starts_with($write['file'], 'migrations/')) {
            continue;
        }

        if (!$db->hasTable($write['table'])) {
            $problems[] = $write['file'] . ':' . $write['line'] . ' — нет таблицы ' . $write['table'];

            continue;
        }

        $checked++;

        if (!$db->hasColumn($write['table'], $write['column'])) {
            $problems[] = $write['file'] . ':' . $write['line']
                . ' — в таблице ' . $write['table'] . ' нет колонки ' . $write['column'];
        }
    }

    assertSame([], $problems, 'код обгоняет миграции');
    assertTrue($checked >= 50, 'разбор нашёл слишком мало записей в базу: ' . $checked);
});

test('запросы обращаются к существующим таблицам', function (): void {
    $db       = Database::instance();
    $problems = [];
    $seen     = [];

    // Служебное: временная таблица подсчёта страниц и системные таблицы самих СУБД
    $skip = ['page_source', 'sqlite_master', 'information_schema', 'dual'];

    foreach (projectPhpFiles() as $file) {
        if (!str_contains($file, '/src/')) {
            continue;
        }

        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            // UPDATE ловим только в начале запроса: дальше в строке это уже
            // «FOR UPDATE SKIP LOCKED» и «ON DUPLICATE KEY UPDATE», а не имя таблицы
            preg_match_all('/\b(?:FROM|JOIN|INSERT\s+INTO)\s+([a-z_][a-z0-9_]*)/i', $token[1], $matches);
            preg_match_all('/^\s*[\'"]?\s*UPDATE\s+([a-z_][a-z0-9_]*)/im', $token[1], $updates);

            foreach (array_merge($matches[1], $updates[1]) as $table) {
                $table = strtolower($table);

                if (in_array($table, $skip, true) || isset($seen[$table])) {
                    continue;
                }

                $seen[$table] = true;

                if (!$db->hasTable($table)) {
                    $problems[] = shortPath($file) . ':' . $token[2] . ' — нет таблицы ' . $table;
                }
            }
        }
    }

    assertSame([], $problems, 'запрос ходит в таблицу, которой нет в схеме');
    assertTrue(count($seen) >= 8, 'разбор нашёл слишком мало таблиц: ' . count($seen));
});

test('накатанная схема совпадает с полным откатом и накатом', function (): void {
    $fresh = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => ':memory:']]);

    (new Mailer\Storage\Migrator($fresh))->run();

    $tables = static function (Database $db): array {
        $names = array_column(
            $db->select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"),
            'name'
        );

        sort($names);

        return $names;
    };

    $before = $tables($fresh);

    (new Mailer\Storage\Migrator($fresh))->rollback();
    (new Mailer\Storage\Migrator($fresh))->run();

    assertSame($before, $tables($fresh), 'после отката и повторного наката схема должна быть той же');

    // И то же самое сравниваем с базой, на которой идут остальные тесты
    assertSame($before, $tables(Database::instance()), 'схема тестовой базы разошлась с чистым накатом');
});
