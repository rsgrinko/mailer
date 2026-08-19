<?php

declare(strict_types=1);

/**
 * Проверки самих запросов. Тесты гоняются на SQLite, поэтому чисто MySQL-овые
 * грабли ловим статически — чтением исходников.
 */

test('в запросах нет повторяющихся именованных параметров', function (): void {
    $problems = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(MAILER_ROOT . '/src'));

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

test('оборванное соединение с MySQL поднимается заново', function (): void {
    // Тесты идут на sqlite в памяти, поэтому подключение к MySQL собираем сами из .env
    if (Mailer\Support\Env::string('DB_DRIVER', 'sqlite') !== 'mysql') {
        skipTest('проверка только для MySQL (DB_DRIVER=mysql в .env)');
    }

    $config = [
        'host'     => Mailer\Support\Env::string('DB_HOST', '127.0.0.1'),
        'port'     => Mailer\Support\Env::int('DB_PORT', 3306),
        'database' => Mailer\Support\Env::string('DB_DATABASE', 'mailer'),
        'username' => Mailer\Support\Env::string('DB_USERNAME', ''),
        'password' => Mailer\Support\Env::string('DB_PASSWORD', ''),
    ];

    $db = new Mailer\Storage\Database(['driver' => 'mysql', 'mysql' => $config]);
    $id = (int) $db->value('SELECT CONNECTION_ID()');

    // Рвём соединение снаружи — так же его закрывает MySQL по wait_timeout
    $killer = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s', $config['host'], $config['port'], $config['database']),
        (string) $config['username'],
        (string) $config['password']
    );
    $killer->exec('KILL ' . $id);
    usleep(300000);

    $again = (int) $db->value('SELECT CONNECTION_ID()');

    assertTrue($again > 0, 'запрос после обрыва должен выполниться');
    assertTrue($again !== $id, 'соединение должно быть новым');
});
