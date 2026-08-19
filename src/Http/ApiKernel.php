<?php

declare(strict_types=1);

namespace Mailer\Http;

use Mailer\Http\Middleware\ApiKey;
use Mailer\Support\Config;
use Mailer\Support\Logger;
use Mailer\Support\MailerException;
use Mailer\Support\ValidationException;
use Throwable;

/**
 * Ядро HTTP API: собирает роутер и превращает исключения в JSON-ошибки.
 * Сами маршруты лежат в routes/api.php.
 */
final class ApiKernel
{
    private Router $router;
    private Logger $logger;

    public function __construct()
    {
        $this->logger = new Logger('api');
        $this->router = (new Router())
            ->middleware('api-key', new ApiKey(null, $this->logger))
            ->load(MAILER_ROOT . '/routes/api.php');
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
}
