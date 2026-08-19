<?php

declare(strict_types=1);

/**
 * Единая точка входа: HTTP API и веб-панель.
 *
 *   /            — уводит на панель
 *   /api/v1/...  — API для приложений (нужен ключ)
 *   /ui/...      — панель управления (вход по логину и паролю)
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

// Корень — панель: человек, зашедший на адрес сервиса, ждёт её, а не JSON
if ($request->path === '' || $request->path === '/') {
    Response::redirect('/ui/')->send();

    return;
}

// Остальное: короткая справка, чтобы было видно, что сервис жив и куда идти
Response::json([
    'service' => (string) Mailer\Support\Config::get('app.name', 'Mailer'),
    'api'     => '/api/v1',
    'health'  => '/api/v1/health',
    'panel'   => '/ui/',
], 404)->send();
