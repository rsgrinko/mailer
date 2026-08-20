<?php

declare(strict_types=1);

namespace Mailer\Ui;

use Mailer\Http\Request;
use Mailer\Http\Response;
use Mailer\Http\Router;
use Mailer\Support\Config;
use Mailer\Support\Logger;
use Mailer\Ui\Middleware\Can;
use Mailer\Ui\Middleware\CsrfGuard;
use Mailer\Ui\Middleware\PanelAuth;
use Mailer\Ui\Middleware\PanelGuest;
use Mailer\Ui\Middleware\PanelSetup;
use Throwable;

/**
 * Веб-панель. Вход по логину и паролю (пользователи в таблице users), права приходят
 * из роли; авторизацию можно выключить настройкой UI_AUTH, если панель уже закрыта
 * на nginx — тогда доступно всё. Отсюда управляется очередь, письма, транспорты,
 * проекты, шаблоны, вебхуки, логи и состояние сервиса.
 *
 * Маршруты и то, кого куда пускать, описаны в routes/ui.php: прослойка can у группы
 * закрывает раздел, а Scope внутри репозиториев решает, чьи записи в нём видно.
 */
final class UiKernel
{
    private ?Router $router = null;
    private Logger $logger;

    public function __construct()
    {
        $this->logger = new Logger('ui');
    }

    public function handle(Request $request): Response
    {
        try {
            return $this->router()->dispatch($request);
        } catch (RecordNotFound $e) {
            // Записи нет — говорим об этом и возвращаем в список раздела
            View::flash($e->getMessage(), 'error');

            return Response::redirect(View::route($e->route()));
        } catch (Throwable $e) {
            // По этому коду ошибку находят в логе: пользователю текст исключения показывать нечего
            $code = strtoupper(bin2hex(random_bytes(3)));

            $this->logger->error('Ошибка панели', [
                'code'  => $code,
                'path'  => $request->path,
                'error' => $e->getMessage(),
                'file'  => $e->getFile() . ':' . $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            $details = (bool) Config::get('app.debug', false)
                ? $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ')'
                : 'Внутренняя ошибка сервиса. Подробности в логе, код ошибки: ' . $code;

            return Response::html(
                View::render('error', ['message' => $details], 'Ошибка'),
                500
            );
        }
    }

    /**
     * Роутер собирается при первом запросе, а не в конструкторе: прослойки лезут в базу,
     * и её падение должно попасть в обработчик ошибок, а не в пустой ответ.
     */
    private function router(): Router
    {
        if ($this->router === null) {
            $this->router = (new Router())
                ->middleware('csrf', new CsrfGuard())
                ->middleware('can', new Can())
                ->middleware('panel-auth', new PanelAuth())
                ->middleware('panel-guest', new PanelGuest())
                ->middleware('panel-setup', new PanelSetup())
                ->load(MAILER_ROOT . '/routes/ui.php');
        }

        return $this->router;
    }
}
