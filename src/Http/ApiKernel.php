<?php

declare(strict_types=1);

namespace Mailer\Http;

use Mailer\Http\Controllers\HealthController;
use Mailer\Http\Controllers\MessagesController;
use Mailer\Http\Controllers\TemplatesController;
use Mailer\Repository\ProjectRepository;
use Mailer\Support\Config;
use Mailer\Support\Logger;
use Mailer\Support\MailerException;
use Mailer\Support\ValidationException;
use Throwable;

/**
 * Ядро HTTP API: маршруты, проверка ключа и превращение исключений в JSON-ошибки.
 */
final class ApiKernel
{
    private Router $router;
    private ProjectRepository $projects;
    private Logger $logger;

    public function __construct()
    {
        $this->router   = new Router();
        $this->projects = new ProjectRepository();
        $this->logger   = new Logger('api');

        $this->registerRoutes();
    }

    /**
     * Обрабатывает запрос и возвращает ответ.
     */
    public function handle(Request $request): Response
    {
        try {
            return $this->router->dispatch($request);
        } catch (ValidationException $e) {
            return Response::error($e->getMessage(), 422, ['errors' => $e->errors()]);
        } catch (MailerException $e) {
            $status = $e->getCode() >= 400 && $e->getCode() < 600 ? (int) $e->getCode() : 400;

            $this->logger->warning('Ошибка обработки запроса', [
                'path'  => $request->path,
                'error' => $e->getMessage(),
            ]);

            return Response::error($e->getMessage(), $status, $e->getContext());
        } catch (Throwable $e) {
            $this->logger->error('Непредвиденная ошибка', [
                'path'  => $request->path,
                'error' => $e->getMessage(),
                'file'  => $e->getFile() . ':' . $e->getLine(),
            ]);

            $details = Config::get('app.debug', false)
                ? ['exception' => $e->getMessage(), 'file' => $e->getFile() . ':' . $e->getLine()]
                : [];

            return Response::error('Внутренняя ошибка сервиса', 500, $details);
        }
    }

    private function registerRoutes(): void
    {
        $messages  = new MessagesController();
        $templates = new TemplatesController();
        $health    = new HealthController();

        // Проверка сервиса — без ключа, чтобы её мог дёргать мониторинг
        $this->router->get('/api/v1/health', fn (Request $r, array $p): Response => $health->health($r));

        $this->router->post('/api/v1/messages', $this->auth(fn (Request $r, array $p, array $project): Response => $messages->create($r, $project)));
        $this->router->get('/api/v1/messages', $this->auth(fn (Request $r, array $p, array $project): Response => $messages->index($r, $project)));
        $this->router->get('/api/v1/messages/{id}', $this->auth(fn (Request $r, array $p, array $project): Response => $messages->show($r, $project, (string) $p['id'])));
        $this->router->post('/api/v1/messages/{id}/retry', $this->auth(fn (Request $r, array $p, array $project): Response => $messages->retry($r, $project, (string) $p['id'])));
        $this->router->delete('/api/v1/messages/{id}', $this->auth(fn (Request $r, array $p, array $project): Response => $messages->cancel($r, $project, (string) $p['id'])));

        $this->router->get('/api/v1/templates', $this->auth(fn (Request $r, array $p, array $project): Response => $templates->index($r)));

        // Короткий адрес — им удобно пользоваться из bash-скрипта
        $this->router->post('/api/v1/send', $this->auth(fn (Request $r, array $p, array $project): Response => $messages->create($r, $project)));
    }

    /**
     * Оборачивает обработчик проверкой API-ключа.
     */
    private function auth(callable $handler): callable
    {
        return function (Request $request, array $params) use ($handler): Response {
            $key = $request->bearerToken();

            if ($key === '') {
                return Response::error('Не передан API-ключ. Ожидается заголовок Authorization: Bearer <ключ>', 401);
            }

            $project = $this->projects->findByApiKey($key);

            if ($project === null) {
                $this->logger->warning('Неверный API-ключ', ['ip' => $request->ip()]);

                return Response::error('Неверный API-ключ', 401);
            }

            if ((int) $project['active'] !== 1) {
                return Response::error('Проект «' . $project['name'] . '» отключён', 403);
            }

            return $handler($request, $params, $project);
        };
    }
}
