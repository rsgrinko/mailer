<?php

declare(strict_types=1);

/**
 * Консольные команды: реестр, имена и справка.
 */

use Mailer\Console\Application;
use Mailer\Console\Command;

test('каждая команда объявлена одна и знает своё имя', function (): void {
    $names = [];

    foreach (Application::commands() as $command) {
        assertTrue($command instanceof Command, 'команда должна наследовать Command');

        $name = $command->name();

        assertTrue($name !== '', 'у команды должно быть имя');
        assertTrue(!in_array($name, $names, true), 'имя команды повторяется: ' . $name);
        assertTrue($command->description() !== '', 'у команды ' . $name . ' нет описания');
        assertTrue(str_starts_with($command->usage(), $name), 'строка вызова ' . $name . ' должна начинаться с имени');

        $names[] = $name;
    }

    assertTrue(count($names) >= 30, 'команд в реестре: ' . count($names));
});

test('справка показывает все команды', function (): void {
    ob_start();
    (new Application())->run(['bin/mailer', 'help']);
    $help = (string) ob_get_clean();

    foreach (Application::commands() as $command) {
        assertContains($command->name(), $help);
    }
});

test('неизвестная команда отвечает подсказкой и кодом 1', function (): void {
    ob_start();
    $code = (new Application())->run(['bin/mailer', 'нет-такой-команды']);
    $output = (string) ob_get_clean();

    assertSame(1, $code);
    assertContains('Неизвестная команда', $output);
});
