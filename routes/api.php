<?php

declare(strict_types=1);

/**
 * Маршруты HTTP API. Всё, кроме health, требует ключ проекта — его проверяет
 * прослойка api-key и кладёт найденный проект в запрос.
 */

use Mailer\Http\Controllers\HealthController;
use Mailer\Http\Controllers\MessagesController;
use Mailer\Http\Controllers\TemplatesController;
use Mailer\Http\Router;

return static function (Router $router): void {
    $router->group(['prefix' => '/api/v1'], static function (Router $router): void {
        // Проверка сервиса — без ключа, чтобы её мог дёргать мониторинг
        $router->get('/health', [HealthController::class, 'health'])->name('api.health');

        $router->group(['middleware' => 'api-key'], static function (Router $router): void {
            $router->post('/messages', [MessagesController::class, 'create'])->name('api.messages.create');
            $router->get('/messages', [MessagesController::class, 'index'])->name('api.messages.index');
            $router->get('/messages/{id}', [MessagesController::class, 'show'])->name('api.messages.show');
            $router->post('/messages/{id}/retry', [MessagesController::class, 'retry'])->name('api.messages.retry');
            $router->delete('/messages/{id}', [MessagesController::class, 'cancel'])->name('api.messages.cancel');

            $router->get('/templates', [TemplatesController::class, 'index'])->name('api.templates.index');

            // Короткий адрес — им удобно пользоваться из bash-скрипта
            $router->post('/send', [MessagesController::class, 'create'])->name('api.send');
        });
    });
};
