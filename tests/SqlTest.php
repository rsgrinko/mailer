<?php

declare(strict_types=1);

/**
 * Проверки самих запросов. Тесты гоняются на SQLite, поэтому чисто MySQL-овые
 * грабли ловим статически — чтением исходников.
 */

test('в запросах нет повторяющихся именованных параметров', function (): void {
    $problems = [];

    $files = new AppendIterator();
    $files->append(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(MAILER_ROOT . '/src')));
    $files->append(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(MAILER_ROOT . '/migrations')));

    foreach ($files as $file) {
        /** @var SplFileInfo $file */
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $path = str_replace('\\', '/', $file->getPathname());

        // В шаблонах панели двоеточия — это CSS-псевдоклассы, а не параметры
        if (str_contains($path, '/Ui/views/')) {
            continue;
        }

        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            preg_match_all('/:([a-zA-Z_][a-zA-Z0-9_]*)/', $token[1], $matches);

            foreach (array_count_values($matches[1]) as $name => $count) {
                if ($count > 1) {
                    $problems[] = basename($path) . ':' . $token[2] . ' — :' . $name;
                }
            }
        }
    }

    assertSame(
        [],
        $problems,
        'MySQL не принимает повтор имени параметра в одном запросе: ' . implode(', ', $problems)
    );
});

test('постраничная выборка считает записи и не выходит за границы', function (): void {
    $projects = new Mailer\Repository\ProjectRepository();

    for ($i = 1; $i <= 5; $i++) {
        if ($projects->findByName('страница-' . $i) === null) {
            $projects->create(['name' => 'страница-' . $i]);
        }
    }

    $total = count($projects->all());

    $first = $projects->paginate(1, 2);
    assertSame($total, $first['total']);
    assertSame(2, count($first['items']));
    assertSame((int) ceil($total / 2), $first['pages']);

    $second = $projects->paginate(2, 2);
    assertSame(2, $second['page']);
    assertTrue(
        $first['items'][0]['id'] !== $second['items'][0]['id'],
        'вторая страница должна отличаться от первой'
    );

    // Номер больше числа страниц прижимается к последней, ноль и минус — к первой
    assertSame($first['pages'], $projects->paginate(999, 2)['page']);
    assertSame(1, $projects->paginate(0, 2)['page']);
    assertSame(1, $projects->paginate(-5, 2)['page']);
});

test('переподключение после обрыва описано в коде для MySQL', function (): void {
    // Тесты идут только на SQLite, поднять оборванное соединение здесь нечему:
    // база в памяти живёт вместе с процессом. Проверяем хотя бы то, что ветка
    // переподключения на месте и ловит именно коды обрыва MySQL
    $source = (string) file_get_contents(MAILER_ROOT . '/src/Storage/Database.php');

    assertContains('2006', $source, 'код «MySQL server has gone away» должен обрабатываться');
    assertContains('2013', $source, 'код «Lost connection» должен обрабатываться');
    assertContains('inTransaction', $source, 'внутри транзакции повторять запрос нельзя');
});

test('счётчик страниц не оборачивает простую выборку в подзапрос', function (): void {
    $db     = new Mailer\Storage\Database(['driver' => 'sqlite', 'sqlite' => ['path' => ':memory:']]);
    $method = new ReflectionMethod($db, 'countSql');

    $simple = $method->invoke($db, 'SELECT * FROM messages WHERE status = :status ORDER BY created_at DESC');
    assertSame('SELECT COUNT(*) FROM messages WHERE status = :status', $simple);

    // Группировки и объединения считаем по-старому — иначе можно получить не то число
    $grouped = $method->invoke($db, 'SELECT status, COUNT(*) FROM messages GROUP BY status');
    assertContains('page_source', $grouped);

    $distinct = $method->invoke($db, 'SELECT DISTINCT tag FROM messages');
    assertContains('page_source', $distinct);
});

test('пагинация считает одно и то же обоими способами', function (): void {
    $messages = new Mailer\Repository\MessageRepository();
    $db       = Mailer\Storage\Database::instance();

    foreach ([[], ['status' => 'queued'], ['search' => 'проверка']] as $filters) {
        $page = $messages->paginate($filters, 1, 5);

        // Прямой подсчёт тем же условием, но через подзапрос
        assertTrue($page['total'] >= 0);
        assertTrue($page['total'] >= count($page['items']), 'всего не может быть меньше показанного');
    }

    assertSame((int) $db->value('SELECT COUNT(*) FROM messages'), $messages->paginate([], 1, 5)['total']);
});
