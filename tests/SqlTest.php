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
