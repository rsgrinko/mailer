<?php

declare(strict_types=1);

/**
 * Соглашения проекта, которые до сих пор держались на одной внимательности:
 * строгая типизация, только фигурные скобки, токен в формах, адреса через
 * имена маршрутов, разметка списков. Проверяются чтением исходников — так
 * забытая мелочь всплывает сразу, а не через полгода на телефоне.
 */

/**
 * Все PHP-файлы проекта, кроме служебных каталогов.
 *
 * @return array<int, string>
 */
function projectPhpFiles(): array
{
    static $files = null;

    if ($files !== null) {
        return $files;
    }

    $files = [];

    foreach (['src', 'migrations', 'routes', 'tests', 'tools', 'public', 'integrations'] as $dir) {
        $path = MAILER_ROOT . '/' . $dir;

        if (!is_dir($path)) {
            continue;
        }

        $walker = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));

        foreach ($walker as $file) {
            /** @var SplFileInfo $file */
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $name = str_replace('\\', '/', $file->getPathname());

            // Плагины для чужих движков живут по правилам этих движков: WordPress и
            // DokuWiki держат PHP 7.0 и свой стиль файлов, наши соглашения там не к месту
            if (str_contains($name, '/integrations/wordpress/') || str_contains($name, '/integrations/dokuwiki/')) {
                continue;
            }

            $files[] = $name;
        }
    }

    sort($files);

    return $files;
}

/**
 * Путь от корня проекта — так в сообщении об ошибке видно, где искать.
 */
function shortPath(string $path): string
{
    return ltrim(str_replace(MAILER_ROOT, '', str_replace('\\', '/', $path)), '/');
}

test('в каждом файле объявлена строгая типизация', function (): void {
    $problems = [];

    foreach (projectPhpFiles() as $file) {
        $head = (string) file_get_contents($file, false, null, 0, 400);

        if (!str_contains($head, 'declare(strict_types=1);')) {
            $problems[] = shortPath($file);
        }
    }

    assertSame([], $problems, 'файлы без declare(strict_types=1)');
});

test('альтернативный синтаксис не используется', function (): void {
    $problems = [];

    foreach (projectPhpFiles() as $file) {
        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            if (!is_array($token)) {
                continue;
            }

            if (in_array($token[0], [T_ENDIF, T_ENDFOREACH, T_ENDWHILE, T_ENDFOR, T_ENDSWITCH], true)) {
                $problems[] = shortPath($file) . ':' . $token[2];
            }
        }
    }

    assertSame([], $problems, 'вместо if (…): … endif; в проекте только фигурные скобки');
});

test('в каждой POST-форме панели есть токен', function (): void {
    $problems = [];

    // Страница отписки открыта наружу: ни сессии, ни токена там нет и быть не может
    $public = ['unsubscribe.php'];

    foreach (glob(MAILER_ROOT . '/src/Ui/views/*.php') ?: [] as $file) {
        if (in_array(basename($file), $public, true)) {
            continue;
        }

        $body  = (string) file_get_contents($file);
        $forms = preg_match_all('/<form[^>]*method\s*=\s*["\']post["\']/i', $body);
        $csrf  = substr_count($body, 'View::csrf()');

        if ($forms > $csrf) {
            $problems[] = basename($file) . ': форм ' . $forms . ', токенов ' . $csrf;
        }
    }

    assertSame([], $problems, 'форма без View::csrf() получит 403 от прослойки csrf');
});

test('адреса во вьюхах собираются по именам маршрутов', function (): void {
    $problems = [];

    foreach (glob(MAILER_ROOT . '/src/Ui/views/*.php') ?: [] as $file) {
        $body = (string) file_get_contents($file);

        // Ищем строковый адрес панели или API в кавычках: href="/ui/messages" и подобное
        if (preg_match('~["\'](/ui/|/api/v1/)~', $body, $match) === 1) {
            $problems[] = basename($file) . ' — ' . $match[1];
        }
    }

    assertSame([], $problems, 'во вьюхах адрес берётся из View::route(), иначе он разъедется с routes/ui.php');
});

test('шапка списка помечена классом head', function (): void {
    $problems = [];

    foreach (glob(MAILER_ROOT . '/src/Ui/views/*.php') ?: [] as $file) {
        $body = (string) file_get_contents($file);

        // Строка с <th> внутри таблицы-списка обязана быть tr.head: на телефоне
        // список разворачивается в карточки, и непомеченная шапка останется висеть
        preg_match_all('~<table[^>]*class="[^"]*\blist\b[^"]*"(.*?)</table>~is', $body, $tables);

        foreach ($tables[1] as $index => $table) {
            preg_match_all('~<tr([^>]*)>(?=.*?<th)~is', $table, $rows);

            foreach ($rows[1] as $attributes) {
                if (!str_contains($attributes, 'head')) {
                    $problems[] = basename($file) . ', таблица #' . ($index + 1);
                }
            }
        }
    }

    assertSame([], $problems, 'строка со <th> в table.list должна иметь class="head"');
});

test('у страниц панели есть имена маршрутов', function (): void {
    $router = new Mailer\Http\Router();
    $router->load(MAILER_ROOT . '/routes/ui.php');

    $nameless = [];

    foreach ($router->routes() as $route) {
        // Заглушка 404 ловит всё подряд, ссылаться на неё неоткуда
        if (str_contains($route->pattern, '{path')) {
            continue;
        }

        // Имя нужно тем адресам, на которые ссылаются: это все страницы (GET).
        // Обработчики форм зовутся из разметки по имени страницы, им имя не нужно
        if ($route->allows('GET') && $route->name === null) {
            $nameless[] = $route->pattern;
        }
    }

    assertSame([], $nameless, 'без имени на страницу нельзя сослаться через View::route()');
});

test('каждая настройка из кода описана в .env.example', function (): void {
    $known = [];

    foreach ((array) file(MAILER_ROOT . '/.env.example', FILE_IGNORE_NEW_LINES) as $line) {
        $line = trim((string) $line);

        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        $known[] = trim(explode('=', $line, 2)[0]);
    }

    $missing = [];

    // Настройки читаются только через Env::string|int|bool|array — их и ищем.
    // Кроме кода сервиса смотрим и сам config.php: там их большая часть
    $files = array_merge(
        array_filter(projectPhpFiles(), static fn (string $file): bool => str_contains($file, '/src/')),
        [MAILER_ROOT . '/config/config.php']
    );

    foreach ($files as $file) {
        preg_match_all(
            '/Env::(?:string|int|bool|array)\(\s*[\'"]([A-Z][A-Z0-9_]*)[\'"]/',
            (string) file_get_contents($file),
            $matches
        );

        foreach ($matches[1] as $key) {
            if (!in_array($key, $known, true) && !in_array($key, $missing, true)) {
                $missing[] = shortPath($file) . ' — ' . $key;
            }
        }
    }

    assertSame([], $missing, 'настройка есть в коде, но её нет в .env.example');
});
