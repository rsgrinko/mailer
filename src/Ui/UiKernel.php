<?php

declare(strict_types=1);

namespace Mailer\Ui;

use Mailer\Http\Request;
use Mailer\Http\Response;
use Mailer\Http\Router;
use Mailer\Repository\UserRepository;
use Mailer\Support\Config;
use Mailer\Support\Logger;
use Mailer\Ui\Controllers\AuthController;
use Mailer\Ui\Controllers\DashboardController;
use Mailer\Ui\Controllers\MessagesController;
use Mailer\Ui\Controllers\ProjectsController;
use Mailer\Ui\Controllers\TemplatesController;
use Mailer\Ui\Controllers\TransportsController;
use Mailer\Ui\Controllers\UsersController;
use Mailer\Ui\Controllers\WebhooksController;
use Throwable;

/**
 * Веб-панель. Вход по логину и паролю (пользователи в таблице users, права у всех едины);
 * авторизацию можно выключить настройкой UI_AUTH, если панель уже закрыта на nginx.
 * Всё, что есть в базе, видно и управляется отсюда: очередь, письма, транспорты,
 * проекты, шаблоны, вебхуки, логи и состояние сервиса.
 */
final class UiKernel
{
    /** Страницы, куда пускаем без авторизации */
    private const PUBLIC_PATHS = ['/ui/login', '/ui/setup'];

    private Router $router;
    private Logger $logger;
    private UserRepository $users;

    public function __construct()
    {
        $this->router = new Router();
        $this->logger = new Logger('ui');
        $this->users  = new UserRepository();

        $this->registerRoutes();
    }

    public function handle(Request $request): Response
    {
        try {
            $denied = $this->guard($request);
            if ($denied !== null) {
                return $denied;
            }

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

    /**
     * Пускать ли запрос дальше. Возвращает ответ-перенаправление, если входить рано.
     */
    private function guard(Request $request): ?Response
    {
        if (!Auth::enabled()) {
            return null;
        }

        Auth::start();

        $path   = rtrim($request->path, '/');
        $public = in_array($path, self::PUBLIC_PATHS, true);

        if (Auth::check()) {
            // Вошедшему на форме входа делать нечего
            return $public ? Response::redirect(View::url('/')) : null;
        }

        // Пользователей ещё нет — первым делом заводим себя
        if ($this->users->count() === 0) {
            return $path === '/ui/setup' ? null : Response::redirect(View::url('/setup'));
        }

        if ($path === '/ui/login') {
            return null;
        }

        // Куда вернуть после входа: только для обычных переходов, POST повторять не будем
        $next = $request->method === 'GET' && $path !== '/ui/setup'
            ? $request->path . ($request->query === [] ? '' : '?' . http_build_query($request->query))
            : '';

        return Response::redirect(View::url('/login', $next !== '' ? ['next' => $next] : []));
    }

    private function registerRoutes(): void
    {
        $dashboard = new DashboardController();
        $messages  = new MessagesController();
        $transports = new TransportsController();
        $projects  = new ProjectsController();
        $templates = new TemplatesController();
        $webhooks  = new WebhooksController();
        $users     = new UsersController();
        $auth      = new AuthController();

        // Вход, выход и первый запуск
        $this->router->get('/ui/login', fn (Request $r, array $p): Response => $auth->loginForm($r));
        $this->router->post('/ui/login', fn (Request $r, array $p): Response => $auth->login($r));
        $this->router->post('/ui/logout', fn (Request $r, array $p): Response => $auth->logout($r));
        $this->router->get('/ui/setup', fn (Request $r, array $p): Response => $auth->setupForm($r));
        $this->router->post('/ui/setup', fn (Request $r, array $p): Response => $auth->setup($r));

        // Пользователи панели
        $this->router->get('/ui/users', fn (Request $r, array $p): Response => $users->index($r));
        $this->router->get('/ui/users/new', fn (Request $r, array $p): Response => $users->form($r, null));
        $this->router->post('/ui/users/save', fn (Request $r, array $p): Response => $users->save($r));
        $this->router->get('/ui/users/{id}', fn (Request $r, array $p): Response => $users->form($r, (int) $p['id']));
        $this->router->post('/ui/users/{id}/{action}', fn (Request $r, array $p): Response => $users->action($r, (int) $p['id'], (string) $p['action']));

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
