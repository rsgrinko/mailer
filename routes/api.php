<?php

declare(strict_types=1);

/**
 * Маршруты HTTP API. Всё, кроме health, требует ключ проекта — его проверяет
 * прослойка api-key и кладёт найденный проект в запрос.
 */

use Mailer\Http\Controllers\HealthController;
use Mailer\Http\Controllers\MessagesController;
use Mailer\Http\Controllers\MetricsController;
use Mailer\Http\Controllers\SuppressionsController;
use Mailer\Http\Controllers\UnsubscribeController;
use Mailer\Http\Controllers\TemplatesController;
use Mailer\Http\Router;

return static function (Router $router): void {
    // Отписка по ссылке из письма: адрес публичный, всё решает подпись в токене
    $router->get('/unsubscribe/{token}', [UnsubscribeController::class, 'form'])->name('unsubscribe.form');
    $router->post('/unsubscribe/{token}', [UnsubscribeController::class, 'submit'])->name('unsubscribe.submit');

    // Метрики для Prometheus: свой токен, отдельно от ключей проектов
    $router->group(['middleware' => 'metrics-token'], static function (Router $router): void {
        $router->get('/metrics', [MetricsController::class, 'index'])->name('api.metrics');
        $router->get('/api/v1/metrics', [MetricsController::class, 'index'])->name('api.v1.metrics');
    });

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

            // Стоп-лист: кому проект больше не пишет
            $router->get('/suppressions', [SuppressionsController::class, 'index'])->name('api.suppressions.index');
            $router->post('/suppressions', [SuppressionsController::class, 'create'])->name('api.suppressions.create');
            $router->delete('/suppressions/{email}', [SuppressionsController::class, 'delete'])->name('api.suppressions.delete');

            // Короткий адрес — им удобно пользоваться из bash-скрипта
            $router->post('/send', [MessagesController::class, 'create'])->name('api.send');
        });
    });
};
