<?php

declare(strict_types=1);

/**
 * Общий старт для всех точек входа: API, панели, CLI и тестов.
 * Подключает автозагрузчик, читает конфигурацию и проверяет окружение.
 */

if (PHP_VERSION_ID < 80100) {
    fwrite(STDERR, 'Нужен PHP 8.1 или новее, установлен ' . PHP_VERSION . PHP_EOL);
    exit(1);
}

define('MAILER_ROOT', str_replace('\\', '/', __DIR__));

require MAILER_ROOT . '/src/Autoload.php';

use Mailer\Support\Config;

Config::load();

date_default_timezone_set((string) Config::get('app.timezone', 'UTC'));
mb_internal_encoding('UTF-8');

// Каталоги для рабочих файлов создаём сразу, чтобы потом не ловить ошибки записи
foreach ([Config::get('paths.log'), Config::get('paths.spool'), Config::get('paths.tmp')] as $dir) {
    if (is_string($dir) && !is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
}

if ((bool) Config::get('app.debug', false)) {
    error_reporting(E_ALL);
    ini_set('display_errors', PHP_SAPI === 'cli' ? '1' : '0');
} else {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
    ini_set('display_errors', '0');
}
