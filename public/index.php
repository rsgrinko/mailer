<?php

declare(strict_types=1);

/**
 * Единая точка входа: HTTP API и веб-панель.
 *
 *   /api/v1/...  — API для приложений (нужен ключ)
 *   /ui/...      — панель управления (авторизацию вешаем на nginx)
 *
 * Локальный запуск:
 *   php -S 127.0.0.1:8080 -t public public/index.php
 */

require dirname(__DIR__) . '/bootstrap.php';

use Mailer\Http\ApiKernel;
use Mailer\Http\Request;
use Mailer\Http\Response;
use Mailer\Ui\UiKernel;

$request = Request::fromGlobals();

// Панель
if ($request->path === '/ui' || str_starts_with($request->path, '/ui/')) {
    (new UiKernel())->handle($request)->send();

    return;
}

// API
if (str_starts_with($request->path, '/api/')) {
    (new ApiKernel())->handle($request)->send();

    return;
}

// Всё остальное: короткая справка, чтобы было видно, что сервис жив
Response::json([
    'service' => (string) Mailer\Support\Config::get('app.name', 'Mailer'),
    'api'     => '/api/v1',
    'health'  => '/api/v1/health',
    'panel'   => '/ui/',
])->send();
