<?php

declare(strict_types=1);

namespace Mailer\Http;

use Mailer\Http\Middleware\ApiKey;
use Mailer\Storage\StorageException;
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
    private ?Router $router = null;
    private Logger $logger;

    public function __construct()
    {
        $this->logger = new Logger('api');
    }

    /**
     * Обрабатывает запрос и возвращает ответ.
     */
    public function handle(Request $request): Response
    {
        try {
            return $this->router()->dispatch($request);
        } catch (ValidationException $e) {
            return Response::error($e->getMessage(), 422, ['errors' => $e->errors()]);
        } catch (StorageException $e) {
            // В тексте ошибки базы видны имена таблиц и SQL — клиенту достаётся только код
            return $this->internal($request, $e);
        } catch (MailerException $e) {
            $status = $e->getCode() >= 400 && $e->getCode() < 600 ? (int) $e->getCode() : 400;

            $this->logger->warning('Ошибка обработки запроса', [
                'path'  => $request->path,
                'error' => $e->getMessage(),
            ]);

            return Response::error($e->getMessage(), $status, $e->getContext());
        } catch (Throwable $e) {
            return $this->internal($request, $e);
        }
    }

    /**
     * Внутренняя ошибка: подробности уходят в лог под кодом, наружу — только код.
     */
    private function internal(Request $request, Throwable $e): Response
    {
        $code = strtoupper(bin2hex(random_bytes(3)));

        $this->logger->error('Непредвиденная ошибка', [
            'code'  => $code,
            'path'  => $request->path,
            'error' => $e->getMessage(),
            'file'  => $e->getFile() . ':' . $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ]);

        $details = Config::get('app.debug', false)
            ? ['exception' => $e->getMessage(), 'file' => $e->getFile() . ':' . $e->getLine(), 'code' => $code]
            : ['code' => $code];

        return Response::error('Внутренняя ошибка сервиса', 500, $details);
    }

    /**
     * Роутер собирается при первом запросе: прослойка ходит в базу, и её падение
     * должно стать нормальным JSON-ответом, а не пустым 500.
     */
    private function router(): Router
    {
        if ($this->router === null) {
            $this->router = (new Router())
                ->middleware('api-key', new ApiKey(null, $this->logger))
                ->load(MAILER_ROOT . '/routes/api.php');
        }

        return $this->router;
    }
}
