<?php

declare(strict_types=1);

namespace Mailer\Http;

/**
 * Ответ сервиса. В API это всегда JSON.
 */
final class Response
{
    private int $status;
    private string $body;

    /** @var array<string, string> */
    private array $headers;

    /** @var array<int, string> Готовые значения Set-Cookie: их может быть несколько */
    private array $cookies = [];

    /**
     * @param array<string, string> $headers
     */
    public function __construct(string $body = '', int $status = 200, array $headers = [])
    {
        $this->body    = $body;
        $this->status  = $status;
        $this->headers = $headers;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function json(array $data, int $status = 200): self
    {
        return new self(
            (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            $status,
            ['Content-Type' => 'application/json; charset=utf-8']
        );
    }

    /**
     * Ответ с ошибкой в едином формате.
     *
     * @param array<string, mixed> $details
     */
    public static function error(string $message, int $status = 400, array $details = []): self
    {
        $error = ['message' => $message, 'status' => $status];

        if ($details !== []) {
            $error['details'] = $details;
        }

        return self::json(['error' => $error], $status);
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($html, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function text(string $text, int $status = 200): self
    {
        return new self($text, $status, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    /**
     * Перенаправление — нужно панели после действий.
     */
    public static function redirect(string $location): self
    {
        return new self('', 302, ['Location' => $location]);
    }

    /**
     * Файл на скачивание (например, .eml письма).
     */
    public static function download(string $content, string $fileName, string $type = 'application/octet-stream'): self
    {
        return new self($content, 200, [
            'Content-Type'        => $type,
            'Content-Disposition' => 'attachment; filename="' . str_replace('"', '', $fileName) . '"',
        ]);
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;

        return $this;
    }

    /**
     * Кука в ответ. Отдельно от заголовков: кук в одном ответе бывает несколько
     * (долгая «запомнить меня» и токен форм), а в карте заголовков вторая
     * затёрла бы первую.
     */
    public function withCookie(string $cookie): self
    {
        $this->cookies[] = $cookie;

        return $this;
    }

    /**
     * Куки ответа — нужны проверкам.
     *
     * @return array<int, string>
     */
    public function cookies(): array
    {
        return $this->cookies;
    }

    /**
     * Заголовки ответа — нужны проверкам: куда именно ведёт перенаправление.
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    /**
     * Отдаёт ответ клиенту.
     */
    public function send(): void
    {
        http_response_code($this->status);

        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }

        // Куки добавляем, а не заменяем: PHP кладёт в Set-Cookie куку сессии, и
        // header() со значением по умолчанию (replace = true) её выбрасывал —
        // сессия не держалась, а вместе с ней терялся токен форм
        foreach ($this->cookies as $cookie) {
            header('Set-Cookie: ' . $cookie, false);
        }

        echo $this->body;
    }
}
