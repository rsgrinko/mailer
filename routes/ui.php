<?php

declare(strict_types=1);

/**
 * Маршруты веб-панели.
 *
 * Доступ задаётся прослойкой прямо у группы: panel-auth — только для вошедших,
 * panel-guest — форма входа, panel-setup — страница первого запуска, can:право —
 * раздел, куда пускают не всех (список прав — в Mailer\Domain\Permission).
 * Идентификаторы ограничены цифрами, поэтому /ui/users/new не спутается с /ui/users/{id}.
 */

use Mailer\Http\Request;
use Mailer\Http\Response;
use Mailer\Http\Router;
use Mailer\Ui\Controllers\AuditController;
use Mailer\Ui\Controllers\AuthController;
use Mailer\Ui\Controllers\DashboardController;
use Mailer\Ui\Controllers\MessagesController;
use Mailer\Ui\Controllers\ProjectsController;
use Mailer\Ui\Controllers\RolesController;
use Mailer\Ui\Controllers\SuppressionsController;
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

            // Обзор виден всем вошедшим — просто со своими цифрами
            $router->get('/', [DashboardController::class, 'index'])->name('ui.dashboard');
            $router->get('/profile', [AuthController::class, 'profileForm'])->name('ui.profile');
            $router->post('/profile', [AuthController::class, 'profile']);

            // Логи и состояние сервиса
            $router->group(['middleware' => 'can:system.view'], static function (Router $router): void {
                $router->get('/system', [DashboardController::class, 'system'])->name('ui.system');
            });
            $router->group(['middleware' => 'can:system.manage'], static function (Router $router): void {
                $router->post('/system/{action}', [DashboardController::class, 'systemAction'])->name('ui.system.action');
            });
            $router->group(['middleware' => 'can:audit.view'], static function (Router $router): void {
                $router->get('/audit', [AuditController::class, 'index'])->name('ui.audit');
            });
            $router->group(['middleware' => 'can:logs.view'], static function (Router $router): void {
                $router->get('/logs', [DashboardController::class, 'logs'])->name('ui.logs');
            });

            // Письма
            $router->group(['middleware' => 'can:messages.view'], static function (Router $router): void {
                $router->get('/messages', [MessagesController::class, 'index'])->name('ui.messages');
                $router->get('/messages/{id:\d+}', [MessagesController::class, 'show'])->name('ui.messages.show');
                $router->get('/messages/{id:\d+}/raw', [MessagesController::class, 'raw'])->name('ui.messages.raw');
                $router->get('/messages/{id:\d+}/attachment', [MessagesController::class, 'attachment'])->name('ui.messages.attachment');
            });
            $router->group(['middleware' => 'can:messages.send'], static function (Router $router): void {
                $router->get('/compose', [MessagesController::class, 'composeForm'])->name('ui.compose');
                $router->post('/compose', [MessagesController::class, 'compose']);
            });
            $router->group(['middleware' => 'can:messages.manage'], static function (Router $router): void {
                $router->post('/messages/bulk', [MessagesController::class, 'bulk'])->name('ui.messages.bulk');
            });
            // Отправить сейчас может и тот, кто умеет только писать: право сверяется по действию
            $router->group(['middleware' => 'can:messages.manage|messages.send'], static function (Router $router): void {
                $router->post('/messages/{id:\d+}/{action}', [MessagesController::class, 'action'])->name('ui.messages.action');
            });

            // Транспорты
            $router->group(['middleware' => 'can:transports.view'], static function (Router $router): void {
                $router->get('/transports', [TransportsController::class, 'index'])->name('ui.transports');
                $router->get('/transports/{id:\d+}', [TransportsController::class, 'form'])->name('ui.transports.show');
            });
            $router->group(['middleware' => 'can:transports.manage'], static function (Router $router): void {
                $router->get('/transports/new', [TransportsController::class, 'form'])->name('ui.transports.new');
                $router->post('/transports/save', [TransportsController::class, 'save'])->name('ui.transports.save');
            });
            $router->group(['middleware' => 'can:transports.manage|transports.test'], static function (Router $router): void {
                $router->post('/transports/{id:\d+}/{action}', [TransportsController::class, 'action'])->name('ui.transports.action');
            });

            // Проекты
            $router->group(['middleware' => 'can:projects.view'], static function (Router $router): void {
                $router->get('/projects', [ProjectsController::class, 'index'])->name('ui.projects');
                $router->get('/projects/{id:\d+}', [ProjectsController::class, 'form'])->name('ui.projects.show');
            });
            $router->group(['middleware' => 'can:projects.manage'], static function (Router $router): void {
                $router->get('/projects/new', [ProjectsController::class, 'form'])->name('ui.projects.new');
                $router->post('/projects/save', [ProjectsController::class, 'save'])->name('ui.projects.save');
                $router->post('/projects/{id:\d+}/{action}', [ProjectsController::class, 'action'])->name('ui.projects.action');
            });

            // Шаблоны
            $router->group(['middleware' => 'can:templates.view'], static function (Router $router): void {
                $router->get('/templates', [TemplatesController::class, 'index'])->name('ui.templates');
                $router->get('/templates/{id:\d+}', [TemplatesController::class, 'form'])->name('ui.templates.show');
            });
            $router->group(['middleware' => 'can:templates.manage'], static function (Router $router): void {
                $router->get('/templates/new', [TemplatesController::class, 'form'])->name('ui.templates.new');
                $router->post('/templates/save', [TemplatesController::class, 'save'])->name('ui.templates.save');
                $router->post('/templates/{id:\d+}/{action}', [TemplatesController::class, 'action'])->name('ui.templates.action');
            });

            // Стоп-лист адресов
            $router->group(['middleware' => 'can:suppressions.view'], static function (Router $router): void {
                $router->get('/suppressions', [SuppressionsController::class, 'index'])->name('ui.suppressions');
            });
            $router->group(['middleware' => 'can:suppressions.manage'], static function (Router $router): void {
                $router->post('/suppressions', [SuppressionsController::class, 'store'])->name('ui.suppressions.store');
                $router->post('/suppressions/{id:\d+}/delete', [SuppressionsController::class, 'delete'])->name('ui.suppressions.delete');
            });

            // Вебхуки
            $router->group(['middleware' => 'can:webhooks.view'], static function (Router $router): void {
                $router->get('/webhooks', [WebhooksController::class, 'index'])->name('ui.webhooks');
            });
            $router->group(['middleware' => 'can:webhooks.manage'], static function (Router $router): void {
                $router->post('/webhooks/process', [WebhooksController::class, 'process'])->name('ui.webhooks.process');
                $router->post('/webhooks/{id:\d+}/{action}', [WebhooksController::class, 'action'])->name('ui.webhooks.action');
            });

            // Пользователи панели
            $router->group(['middleware' => 'can:users.manage'], static function (Router $router): void {
                $router->get('/users', [UsersController::class, 'index'])->name('ui.users');
                $router->get('/users/new', [UsersController::class, 'form'])->name('ui.users.new');
                $router->post('/users/save', [UsersController::class, 'save'])->name('ui.users.save');
                $router->get('/users/{id:\d+}', [UsersController::class, 'form'])->name('ui.users.show');
                $router->post('/users/{id:\d+}/{action}', [UsersController::class, 'action'])->name('ui.users.action');
            });

            // Роли: наборы прав, которые выдаются пользователям
            $router->group(['middleware' => 'can:roles.manage'], static function (Router $router): void {
                $router->get('/roles', [RolesController::class, 'index'])->name('ui.roles');
                $router->get('/roles/new', [RolesController::class, 'form'])->name('ui.roles.new');
                $router->post('/roles/save', [RolesController::class, 'save'])->name('ui.roles.save');
                $router->get('/roles/{id:\d+}', [RolesController::class, 'form'])->name('ui.roles.show');
                $router->post('/roles/{id:\d+}/{action}', [RolesController::class, 'action'])->name('ui.roles.action');
            });

            // Неизвестный адрес панели: сначала вход, и только потом 404 — чужому знать нечего
            $router->match(['GET', 'POST'], '/{path:.*}', static fn (Request $request): Response => Response::html(
                View::render('error', ['message' => 'Страница не найдена: ' . $request->path], 'Страница не найдена'),
                404
            ));
        });
    });
};
