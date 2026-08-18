<?php

declare(strict_types=1);

namespace Mailer\Http;

/**
 * Входящий HTTP-запрос в удобном виде.
 */
final class Request
{
    public string $method;
    public string $path;

    /** @var array<string, mixed> */
    public array $query;

    /** @var array<string, string> Заголовки, имена в нижнем регистре */
    public array $headers;

    public string $rawBody;

    /** @var array<string, mixed> Разобранное тело: JSON или обычная форма */
    public array $body;

    /**
     * Собирает запрос из суперглобальных переменных.
     */
    public static function fromGlobals(): self
    {
        $request = new self();

        $request->method  = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $request->path    = rtrim(parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/', '/');
        $request->path    = $request->path === '' ? '/' : $request->path;
        $request->query   = $_GET;
        $request->headers = self::readHeaders();
        $request->rawBody = (string) file_get_contents('php://input');
        $request->body    = self::parseBody($request);

        return $request;
    }

    /**
     * @return array<string, string>
     */
    private static function readHeaders(): array
    {
        $headers = [];

        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name           = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = (string) $value;
            }
        }

        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }
        if (isset($_SERVER['CONTENT_LENGTH'])) {
            $headers['content-length'] = (string) $_SERVER['CONTENT_LENGTH'];
        }

        return $headers;
    }

    /**
     * @return array<string, mixed>
     */
    private static function parseBody(self $request): array
    {
        $type = $request->header('content-type');

        if (str_contains($type, 'application/json')) {
            $decoded = json_decode($request->rawBody, true);

            return is_array($decoded) ? $decoded : [];
        }

        if ($request->method === 'POST' && $_POST !== []) {
            return $_POST;
        }

        if ($request->rawBody !== '' && str_contains($type, 'application/x-www-form-urlencoded')) {
            parse_str($request->rawBody, $parsed);

            return $parsed;
        }

        return [];
    }

    public function header(string $name): string
    {
        return $this->headers[strtolower($name)] ?? '';
    }

    /**
     * API-ключ из заголовка Authorization: Bearer <ключ>.
     * Для совместимости понимаем и X-Api-Key.
     */
    public function bearerToken(): string
    {
        $auth = $this->header('authorization');

        if (stripos($auth, 'bearer ') === 0) {
            return trim(substr($auth, 7));
        }

        $apiKey = $this->header('x-api-key');
        if ($apiKey !== '') {
            return trim($apiKey);
        }

        return '';
    }

    /**
     * Значение из query-строки.
     */
    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    /**
     * Значение из тела запроса.
     */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    /**
     * Адрес клиента — пишем в логи.
     */
    public function ip(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    }
}
