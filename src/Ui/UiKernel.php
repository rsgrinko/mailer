<?php

declare(strict_types=1);

namespace Mailer\Ui;

use Mailer\Http\Request;
use Mailer\Http\Response;
use Mailer\Http\Router;
use Mailer\Support\Config;
use Mailer\Support\Logger;
use Mailer\Ui\Middleware\PanelAuth;
use Mailer\Ui\Middleware\PanelGuest;
use Mailer\Ui\Middleware\PanelSetup;
use Throwable;

/**
 * Веб-панель. Вход по логину и паролю (пользователи в таблице users, права у всех едины);
 * авторизацию можно выключить настройкой UI_AUTH, если панель уже закрыта на nginx.
 * Всё, что есть в базе, видно и управляется отсюда: очередь, письма, транспорты,
 * проекты, шаблоны, вебхуки, логи и состояние сервиса.
 *
 * Маршруты и то, кого куда пускать, описаны в routes/ui.php.
 */
final class UiKernel
{
    private Router $router;
    private Logger $logger;

    public function __construct()
    {
        $this->logger = new Logger('ui');
        $this->router = (new Router())
            ->middleware('panel-auth', new PanelAuth())
            ->middleware('panel-guest', new PanelGuest())
            ->middleware('panel-setup', new PanelSetup())
            ->load(MAILER_ROOT . '/routes/ui.php');
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
}
