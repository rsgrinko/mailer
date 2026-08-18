<?php

declare(strict_types=1);

namespace Mailer\Ui;

use Mailer\Http\Request;
use Mailer\Http\Response;
use Mailer\Http\Router;
use Mailer\Support\Config;
use Mailer\Support\Logger;
use Mailer\Ui\Controllers\DashboardController;
use Mailer\Ui\Controllers\MessagesController;
use Mailer\Ui\Controllers\ProjectsController;
use Mailer\Ui\Controllers\TemplatesController;
use Mailer\Ui\Controllers\TransportsController;
use Mailer\Ui\Controllers\WebhooksController;
use Throwable;

/**
 * Веб-панель. Своей авторизации нет — предполагается basic auth на nginx.
 * Всё, что есть в базе, видно и управляется отсюда: очередь, письма, транспорты,
 * проекты, шаблоны, вебхуки, логи и состояние сервиса.
 */
final class UiKernel
{
    private Router $router;
    private Logger $logger;

    public function __construct()
    {
        $this->router = new Router();
        $this->logger = new Logger('ui');

        $this->registerRoutes();
    }

    public function handle(Request $request): Response
    {
        try {
            return $this->router->dispatch($request);
        } catch (Throwable $e) {
            $this->logger->error('Ошибка панели', [
                'path'  => $request->path,
                'error' => $e->getMessage(),
                'file'  => $e->getFile() . ':' . $e->getLine(),
            ]);

            $details = (bool) Config::get('app.debug', false)
                ? $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ')'
                : $e->getMessage();

            return Response::html(
                View::render('error', ['message' => $details], 'Ошибка'),
                500
            );
        }
    }

    private function registerRoutes(): void
    {
        $dashboard = new DashboardController();
        $messages  = new MessagesController();
        $transports = new TransportsController();
        $projects  = new ProjectsController();
        $templates = new TemplatesController();
        $webhooks  = new WebhooksController();

        // Дашборд и общее состояние
        $this->router->get('/ui', fn (Request $r, array $p): Response => $dashboard->index($r));
        $this->router->get('/ui/system', fn (Request $r, array $p): Response => $dashboard->system($r));
        $this->router->post('/ui/system/{action}', fn (Request $r, array $p): Response => $dashboard->systemAction($r, (string) $p['action']));
        $this->router->get('/ui/logs', fn (Request $r, array $p): Response => $dashboard->logs($r));

        // Письма
        $this->router->get('/ui/messages', fn (Request $r, array $p): Response => $messages->index($r));
        $this->router->get('/ui/compose', fn (Request $r, array $p): Response => $messages->composeForm($r));
        $this->router->post('/ui/compose', fn (Request $r, array $p): Response => $messages->compose($r));
        $this->router->post('/ui/messages/bulk', fn (Request $r, array $p): Response => $messages->bulk($r));
        $this->router->get('/ui/messages/{id}', fn (Request $r, array $p): Response => $messages->show($r, (int) $p['id']));
        $this->router->get('/ui/messages/{id}/raw', fn (Request $r, array $p): Response => $messages->raw($r, (int) $p['id']));
        $this->router->get('/ui/messages/{id}/attachment', fn (Request $r, array $p): Response => $messages->attachment($r, (int) $p['id']));
        $this->router->post('/ui/messages/{id}/{action}', fn (Request $r, array $p): Response => $messages->action($r, (int) $p['id'], (string) $p['action']));

        // Транспорты
        $this->router->get('/ui/transports', fn (Request $r, array $p): Response => $transports->index($r));
        $this->router->get('/ui/transports/new', fn (Request $r, array $p): Response => $transports->form($r, null));
        $this->router->post('/ui/transports/save', fn (Request $r, array $p): Response => $transports->save($r));
        $this->router->get('/ui/transports/{id}', fn (Request $r, array $p): Response => $transports->form($r, (int) $p['id']));
        $this->router->post('/ui/transports/{id}/{action}', fn (Request $r, array $p): Response => $transports->action($r, (int) $p['id'], (string) $p['action']));

        // Проекты
        $this->router->get('/ui/projects', fn (Request $r, array $p): Response => $projects->index($r));
        $this->router->get('/ui/projects/new', fn (Request $r, array $p): Response => $projects->form($r, null));
        $this->router->post('/ui/projects/save', fn (Request $r, array $p): Response => $projects->save($r));
        $this->router->get('/ui/projects/{id}', fn (Request $r, array $p): Response => $projects->form($r, (int) $p['id']));
        $this->router->post('/ui/projects/{id}/{action}', fn (Request $r, array $p): Response => $projects->action($r, (int) $p['id'], (string) $p['action']));

        // Шаблоны
        $this->router->get('/ui/templates', fn (Request $r, array $p): Response => $templates->index($r));
        $this->router->get('/ui/templates/new', fn (Request $r, array $p): Response => $templates->form($r, null));
        $this->router->post('/ui/templates/save', fn (Request $r, array $p): Response => $templates->save($r));
        $this->router->get('/ui/templates/{id}', fn (Request $r, array $p): Response => $templates->form($r, (int) $p['id']));
        $this->router->post('/ui/templates/{id}/{action}', fn (Request $r, array $p): Response => $templates->action($r, (int) $p['id'], (string) $p['action']));

        // Вебхуки
        $this->router->get('/ui/webhooks', fn (Request $r, array $p): Response => $webhooks->index($r));
        $this->router->post('/ui/webhooks/process', fn (Request $r, array $p): Response => $webhooks->process($r));
        $this->router->post('/ui/webhooks/{id}/{action}', fn (Request $r, array $p): Response => $webhooks->action($r, (int) $p['id'], (string) $p['action']));
    }
}
