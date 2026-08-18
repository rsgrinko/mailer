<?php

declare(strict_types=1);

/**
 * Собственный автозагрузчик классов (PSR-4-подобный), composer не используется.
 *
 * Отображение пространств имён на каталоги:
 *   Mailer\      -> src/
 *   Mailer\Sdk\  -> sdk/
 */

if (!defined('MAILER_ROOT')) {
    define('MAILER_ROOT', str_replace('\\', '/', dirname(__DIR__)));
}

spl_autoload_register(static function (string $class): void {
    // SDK — один самодостаточный файл, в нём сразу все свои классы
    if (str_starts_with($class, 'Mailer\\Sdk\\')) {
        require_once MAILER_ROOT . '/sdk/MailerClient.php';

        return;
    }

    $map = [
        'Mailer\\' => MAILER_ROOT . '/src/',
    ];

    foreach ($map as $prefix => $baseDir) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }

        $relative = substr($class, strlen($prefix));
        $file     = $baseDir . str_replace('\\', '/', $relative) . '.php';

        if (is_file($file)) {
            require $file;
            return;
        }
    }
});
