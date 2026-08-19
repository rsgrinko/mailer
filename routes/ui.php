<?php

declare(strict_types=1);

/**
 * Маршруты веб-панели.
 *
 * Доступ задаётся прослойкой прямо у группы: panel-auth — только для вошедших,
 * panel-guest — форма входа, panel-setup — страница первого запуска.
 * Идентификаторы ограничены цифрами, поэтому /ui/users/new не спутается с /ui/users/{id}.
 */

use Mailer\Http\Request;
use Mailer\Http\Response;
use Mailer\Http\Router;
use Mailer\Ui\Controllers\AuthController;
use Mailer\Ui\Controllers\DashboardController;
use Mailer\Ui\Controllers\MessagesController;
use Mailer\Ui\Controllers\ProjectsController;
use Mailer\Ui\Controllers\TemplatesController;
use Mailer\Ui\Controllers\TransportsController;
use Mailer\Ui\Controllers\UsersController;
use Mailer\Ui\Controllers\WebhooksController;
use Mailer\Ui\View;

return static function (Router $router): void {
    $router->group(['prefix' => '/ui', 'middleware' => 'csrf'], static function (Router $router): void {
        // Вход и первый запуск
        $router->group(['middleware' => 'panel-guest'], static function (Router $router): void {
            $router->get('/login', [AuthController::class, 'loginForm'])->name('ui.login');
            $router->post('/login', [AuthController::class, 'login']);
        });

        $router->group(['middleware' => 'panel-setup'], static function (Router $router): void {
            $router->get('/setup', [AuthController::class, 'setupForm'])->name('ui.setup');
            $router->post('/setup', [AuthController::class, 'setup']);
        });

        // Всё остальное — только для вошедших
        $router->group(['middleware' => 'panel-auth'], static function (Router $router): void {
            $router->post('/logout', [AuthController::class, 'logout'])->name('ui.logout');

            // Обзор, логи и состояние сервиса
            $router->get('/', [DashboardController::class, 'index'])->name('ui.dashboard');
            $router->get('/system', [DashboardController::class, 'system'])->name('ui.system');
            $router->post('/system/{action}', [DashboardController::class, 'systemAction'])->name('ui.system.action');
            $router->get('/logs', [DashboardController::class, 'logs'])->name('ui.logs');

            // Письма
            $router->get('/messages', [MessagesController::class, 'index'])->name('ui.messages');
            $router->get('/compose', [MessagesController::class, 'composeForm'])->name('ui.compose');
            $router->post('/compose', [MessagesController::class, 'compose']);
            $router->post('/messages/bulk', [MessagesController::class, 'bulk'])->name('ui.messages.bulk');
            $router->get('/messages/{id:\d+}', [MessagesController::class, 'show'])->name('ui.messages.show');
            $router->get('/messages/{id:\d+}/raw', [MessagesController::class, 'raw'])->name('ui.messages.raw');
            $router->get('/messages/{id:\d+}/attachment', [MessagesController::class, 'attachment'])->name('ui.messages.attachment');
            $router->post('/messages/{id:\d+}/{action}', [MessagesController::class, 'action'])->name('ui.messages.action');

            // Транспорты
            $router->get('/transports', [TransportsController::class, 'index'])->name('ui.transports');
            $router->get('/transports/new', [TransportsController::class, 'form'])->name('ui.transports.new');
            $router->post('/transports/save', [TransportsController::class, 'save'])->name('ui.transports.save');
            $router->get('/transports/{id:\d+}', [TransportsController::class, 'form'])->name('ui.transports.show');
            $router->post('/transports/{id:\d+}/{action}', [TransportsController::class, 'action'])->name('ui.transports.action');

            // Проекты
            $router->get('/projects', [ProjectsController::class, 'index'])->name('ui.projects');
            $router->get('/projects/new', [ProjectsController::class, 'form'])->name('ui.projects.new');
            $router->post('/projects/save', [ProjectsController::class, 'save'])->name('ui.projects.save');
            $router->get('/projects/{id:\d+}', [ProjectsController::class, 'form'])->name('ui.projects.show');
            $router->post('/projects/{id:\d+}/{action}', [ProjectsController::class, 'action'])->name('ui.projects.action');

            // Шаблоны
            $router->get('/templates', [TemplatesController::class, 'index'])->name('ui.templates');
            $router->get('/templates/new', [TemplatesController::class, 'form'])->name('ui.templates.new');
            $router->post('/templates/save', [TemplatesController::class, 'save'])->name('ui.templates.save');
            $router->get('/templates/{id:\d+}', [TemplatesController::class, 'form'])->name('ui.templates.show');
            $router->post('/templates/{id:\d+}/{action}', [TemplatesController::class, 'action'])->name('ui.templates.action');

            // Вебхуки
            $router->get('/webhooks', [WebhooksController::class, 'index'])->name('ui.webhooks');
            $router->post('/webhooks/process', [WebhooksController::class, 'process'])->name('ui.webhooks.process');
            $router->post('/webhooks/{id:\d+}/{action}', [WebhooksController::class, 'action'])->name('ui.webhooks.action');

            // Пользователи панели
            $router->get('/users', [UsersController::class, 'index'])->name('ui.users');
            $router->get('/users/new', [UsersController::class, 'form'])->name('ui.users.new');
            $router->post('/users/save', [UsersController::class, 'save'])->name('ui.users.save');
            $router->get('/users/{id:\d+}', [UsersController::class, 'form'])->name('ui.users.show');
            $router->post('/users/{id:\d+}/{action}', [UsersController::class, 'action'])->name('ui.users.action');

            // Неизвестный адрес панели: сначала вход, и только потом 404 — чужому знать нечего
            $router->match(['GET', 'POST'], '/{path:.*}', static fn (Request $request): Response => Response::html(
                View::render('error', ['message' => 'Страница не найдена: ' . $request->path], 'Страница не найдена'),
                404
            ));
        });
    });
};
