<?php

declare(strict_types=1);

/**
 * Роутер: группы, прослойки, ограничения параметров и подстановка аргументов.
 */

use Mailer\Http\Request;
use Mailer\Http\Response;
use Mailer\Http\Router;
use Mailer\Support\MailerException;

/** Контроллер для проверок: сюда роутер и подставляет аргументы. */
final class RouterTestController
{
    public function show(Request $request, int $id): Response
    {
        return Response::json(['id' => $id, 'type' => get_debug_type($id)]);
    }

    public function form(Request $request, ?int $id): Response
    {
        return Response::json(['id' => $id]);
    }

    public function action(Request $request, int $id, string $action): Response
    {
        return Response::json(['id' => $id, 'action' => $action]);
    }

    /** @param array<string, mixed> $project */
    public function create(Request $request, array $project): Response
    {
        return Response::json(['project' => $project['name']]);
    }
}

function routerRequest(string $method, string $path, array $query = []): Request
{
    $request           = new Request();
    $request->method   = $method;
    $request->path     = rtrim($path, '/') === '' ? '/' : rtrim($path, '/');
    $request->query    = $query;
    $request->headers  = [];
    $request->rawBody  = '';
    $request->body     = [];

    return $request;
}

test('группа добавляет префикс и прослойки', function (): void {
    $order  = [];
    $router = new Router();

    $router->middleware('first', static function (Request $r, callable $next) use (&$order): Response {
        $order[] = 'first';

        return $next($r);
    });

    $router->middleware('second', static function (Request $r, callable $next) use (&$order): Response {
        $order[] = 'second';

        return $next($r);
    });

    $router->group(['prefix' => '/api/v1', 'middleware' => 'first'], static function (Router $router) use (&$order): void {
        $router->group(['middleware' => 'second'], static function (Router $router) use (&$order): void {
            $router->get('/ping', static function (Request $r, array $p) use (&$order): Response {
                $order[] = 'handler';

                return Response::json(['ok' => true]);
            });
        });
    });

    $response = $router->dispatch(routerRequest('GET', '/api/v1/ping'));

    assertSame(200, $response->status());
    assertSame(['first', 'second', 'handler'], $order);
});

test('прослойка может не пустить дальше', function (): void {
    $called = false;
    $router = new Router();

    $router->middleware('stop', static fn (Request $r, callable $next): Response => Response::error('нельзя', 401));

    $router->group(['middleware' => 'stop'], static function (Router $router) use (&$called): void {
        $router->get('/secret', static function (Request $r, array $p) use (&$called): Response {
            $called = true;

            return Response::json([]);
        });
    });

    $response = $router->dispatch(routerRequest('GET', '/secret'));

    assertSame(401, $response->status());
    assertFalse($called, 'обработчик не должен вызываться');
});

test('ограничение параметра не даёт спутать new и id', function (): void {
    $router = new Router();

    // Нарочно объявляем {id} раньше — с ограничением порядок больше не важен
    $router->get('/users/{id:\d+}', [RouterTestController::class, 'show']);
    $router->get('/users/new', static fn (Request $r, array $p): Response => Response::json(['new' => true]));

    assertContains('"new": true', $router->dispatch(routerRequest('GET', '/users/new'))->body());
    assertContains('"id": 7', $router->dispatch(routerRequest('GET', '/users/7'))->body());
});

test('аргументы обработчика подставляются по именам', function (): void {
    $router = new Router();

    $router->middleware('project', static fn (Request $r, callable $next): Response
        => $next($r->setAttribute('project', ['name' => 'demo'])));

    $router->get('/messages/{id:\d+}/{action}', [RouterTestController::class, 'action']);
    $router->get('/form/new', [RouterTestController::class, 'form']);
    $router->group(['middleware' => 'project'], static function (Router $router): void {
        $router->post('/messages', [RouterTestController::class, 'create']);
    });

    $body = $router->dispatch(routerRequest('GET', '/messages/12/retry'))->body();
    assertContains('"id": 12', $body);
    assertContains('"action": "retry"', $body);

    // Параметра в адресе нет — в необязательный аргумент приходит null
    assertContains('"id": null', $router->dispatch(routerRequest('GET', '/form/new'))->body());

    // Проект кладёт прослойка, контроллер получает его аргументом $project
    assertContains('"project": "demo"', $router->dispatch(routerRequest('POST', '/messages'))->body());
});

test('параметр приводится к типу аргумента', function (): void {
    $router = new Router();
    $router->get('/messages/{id:\d+}', [RouterTestController::class, 'show']);

    assertContains('"type": "int"', $router->dispatch(routerRequest('GET', '/messages/42'))->body());
});

test('именованный маршрут собирает адрес', function (): void {
    $router = new Router();

    $router->group(['prefix' => '/ui'], static function (Router $router): void {
        $router->get('/messages/{id:\d+}', [RouterTestController::class, 'show'])->name('test.messages.show');
        $router->get('/messages', [RouterTestController::class, 'show'])->name('test.messages');
    });

    assertSame('/ui/messages/15', Router::url('test.messages.show', ['id' => 15]));
    assertSame('/ui/messages?status=failed', Router::url('test.messages', ['status' => 'failed']));

    assertThrows(static fn (): string => Router::url('test.messages.show'), 'без параметра адрес не собрать');
    assertThrows(static fn (): string => Router::url('нет.такого'), 'неизвестное имя маршрута');
});

test('неизвестная прослойка — это ошибка настройки', function (): void {
    $router = new Router();

    $router->group(['middleware' => 'нет-такой'], static function (Router $router): void {
        $router->get('/ping', static fn (Request $r, array $p): Response => Response::json([]));
    });

    $error = assertThrows(static fn (): Response => $router->dispatch(routerRequest('GET', '/ping')));
    assertTrue($error instanceof MailerException, 'ожидали MailerException');
    assertContains('нет-такой', $error->getMessage());
});

test('файлы маршрутов сервиса загружаются и знают свои адреса', function (): void {
    $router = new Router();
    $router->middleware('api-key', static fn (Request $r, callable $next): Response => $next($r));
    $router->load(MAILER_ROOT . '/routes/api.php');

    // health доступен без ключа, остальное — под прослойкой
    $response = $router->dispatch(routerRequest('GET', '/api/v1/health'));
    assertTrue(in_array($response->status(), [200, 503], true), 'health отвечает 200 или 503');

    assertSame('/api/v1/messages/abc', Router::url('api.messages.show', ['id' => 'abc']));
    assertSame(404, $router->dispatch(routerRequest('GET', '/api/v1/нет'))->status());
    assertSame(405, $router->dispatch(routerRequest('PUT', '/api/v1/messages'))->status());
});
