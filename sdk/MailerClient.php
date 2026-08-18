<?php

declare(strict_types=1);

namespace Mailer\Sdk;

/**
 * Мини-SDK сервиса рассылки — один файл, без зависимостей.
 *
 * Подключение в своём проекте:
 *   require_once '/путь/к/MailerClient.php';
 *
 *   $mailer = new Mailer\Sdk\Client('http://mail.internal', 'mlr_ключ');
 *   $mailer->send(
 *       Mailer\Sdk\Mail::to('user@example.com')
 *           ->subject('Здравствуйте')
 *           ->text('Простое письмо')
 *   );
 *
 * Всё, что делает клиент — отправляет JSON на HTTP API сервиса.
 */

/**
 * Ошибка при обращении к API.
 */
class MailerException extends \RuntimeException
{
    /** @var array<int, string> Список ошибок валидации, если сервис их вернул */
    public array $errors = [];

    /** @var array<string, mixed> Полный ответ сервиса */
    public array $response = [];

    /**
     * @param array<int, string> $errors
     * @param array<string, mixed> $response
     */
    public function __construct(string $message, int $code = 0, array $errors = [], array $response = [])
    {
        parent::__construct($message, $code);

        $this->errors   = $errors;
        $this->response = $response;
    }
}

/**
 * Письмо. Собирается по цепочке: Mail::to(...)->subject(...)->html(...).
 */
class Mail
{
    /** @var array<string, mixed> */
    private array $data = [];

    /**
     * Начало письма: один или несколько получателей.
     *
     * @param string|array<int, string> $to
     */
    public static function to(string|array $to): self
    {
        $mail             = new self();
        $mail->data['to'] = $to;

        return $mail;
    }

    /**
     * Отправитель. Имя необязательно.
     */
    public function from(string $email, string $name = ''): self
    {
        $this->data['from'] = $name === '' ? $email : ['email' => $email, 'name' => $name];

        return $this;
    }

    /**
     * @param string|array<int, string> $cc
     */
    public function cc(string|array $cc): self
    {
        $this->data['cc'] = $cc;

        return $this;
    }

    /**
     * @param string|array<int, string> $bcc
     */
    public function bcc(string|array $bcc): self
    {
        $this->data['bcc'] = $bcc;

        return $this;
    }

    public function replyTo(string $email): self
    {
        $this->data['reply_to'] = $email;

        return $this;
    }

    public function subject(string $subject): self
    {
        $this->data['subject'] = $subject;

        return $this;
    }

    public function text(string $text): self
    {
        $this->data['text'] = $text;

        return $this;
    }

    public function html(string $html): self
    {
        $this->data['html'] = $html;

        return $this;
    }

    /**
     * Письмо по шаблону из сервиса.
     *
     * @param array<string, mixed> $data
     */
    public function template(string $name, array $data = []): self
    {
        $this->data['template']      = $name;
        $this->data['template_data'] = $data;

        return $this;
    }

    /**
     * Вложение из данных в памяти.
     */
    public function attach(string $fileName, string $content, string $contentType = ''): self
    {
        $attachment = [
            'name'    => $fileName,
            'content' => base64_encode($content),
        ];

        if ($contentType !== '') {
            $attachment['content_type'] = $contentType;
        }

        $this->data['attachments'][] = $attachment;

        return $this;
    }

    /**
     * Вложение из файла на диске.
     */
    public function attachFile(string $path, string $fileName = ''): self
    {
        if (!is_file($path)) {
            throw new MailerException('Файл вложения не найден: ' . $path);
        }

        return $this->attach($fileName !== '' ? $fileName : basename($path), (string) file_get_contents($path));
    }

    /**
     * Картинка внутри HTML. В теле письма ссылаться так: <img src="cid:идентификатор">
     */
    public function inlineImage(string $cid, string $path, string $fileName = ''): self
    {
        if (!is_file($path)) {
            throw new MailerException('Файл картинки не найден: ' . $path);
        }

        $this->data['attachments'][] = [
            'name'    => $fileName !== '' ? $fileName : basename($path),
            'content' => base64_encode((string) file_get_contents($path)),
            'inline'  => true,
            'cid'     => $cid,
        ];

        return $this;
    }

    public function header(string $name, string $value): self
    {
        $this->data['headers'][$name] = $value;

        return $this;
    }

    /**
     * Метка — по ней потом удобно искать письма в панели.
     */
    public function tag(string $tag): self
    {
        $this->data['tag'] = $tag;

        return $this;
    }

    /**
     * Произвольные данные, которые вернутся в вебхуке.
     *
     * @param array<string, mixed> $meta
     */
    public function meta(array $meta): self
    {
        $this->data['meta'] = $meta;

        return $this;
    }

    /**
     * Отправить конкретным транспортом (имя из панели).
     */
    public function transport(string $name): self
    {
        $this->data['transport'] = $name;

        return $this;
    }

    /**
     * Меньше число — выше приоритет в очереди. По умолчанию 100.
     */
    public function priority(int $priority): self
    {
        $this->data['priority'] = $priority;

        return $this;
    }

    /**
     * Отложенная отправка: '2026-01-01 10:00:00' или '+2 hours'.
     */
    public function sendAt(string $when): self
    {
        $this->data['send_at'] = $when;

        return $this;
    }

    /**
     * Защита от повторной отправки при ретраях на стороне приложения.
     */
    public function idempotencyKey(string $key): self
    {
        $this->data['idempotency_key'] = $key;

        return $this;
    }

    /**
     * Дождаться отправки вместо постановки в очередь.
     */
    public function sync(bool $sync = true): self
    {
        $this->data['sync'] = $sync;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }
}

/**
 * Клиент API.
 */
class Client
{
    private string $baseUrl;
    private string $apiKey;
    private int $timeout;

    public function __construct(string $baseUrl, string $apiKey, int $timeout = 15)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey  = $apiKey;
        $this->timeout = $timeout;
    }

    /**
     * Отправляет письмо. Принимает объект Mail или обычный массив.
     *
     * @param Mail|array<string, mixed> $mail
     * @return array<string, mixed> ответ сервиса: id, status и прочее
     */
    public function send(Mail|array $mail): array
    {
        $payload = $mail instanceof Mail ? $mail->toArray() : $mail;

        return $this->request('POST', '/api/v1/messages', $payload);
    }

    /**
     * Отправляет письмо и ждёт результата (без очереди).
     *
     * @param Mail|array<string, mixed> $mail
     * @return array<string, mixed>
     */
    public function sendNow(Mail|array $mail): array
    {
        $payload         = $mail instanceof Mail ? $mail->toArray() : $mail;
        $payload['sync'] = true;

        return $this->request('POST', '/api/v1/messages', $payload);
    }

    /**
     * Состояние письма по его идентификатору.
     *
     * @return array<string, mixed>
     */
    public function status(string $id): array
    {
        return $this->request('GET', '/api/v1/messages/' . rawurlencode($id));
    }

    /**
     * Список писем проекта.
     *
     * @param array<string, string|int> $filters status, tag, search, page, per_page
     * @return array<string, mixed>
     */
    public function messages(array $filters = []): array
    {
        $query = $filters === [] ? '' : '?' . http_build_query($filters);

        return $this->request('GET', '/api/v1/messages' . $query);
    }

    /**
     * Повторить неудачное письмо.
     *
     * @return array<string, mixed>
     */
    public function retry(string $id): array
    {
        return $this->request('POST', '/api/v1/messages/' . rawurlencode($id) . '/retry');
    }

    /**
     * Отменить письмо, пока оно в очереди.
     *
     * @return array<string, mixed>
     */
    public function cancel(string $id): array
    {
        return $this->request('DELETE', '/api/v1/messages/' . rawurlencode($id));
    }

    /**
     * Доступные шаблоны писем.
     *
     * @return array<string, mixed>
     */
    public function templates(): array
    {
        return $this->request('GET', '/api/v1/templates');
    }

    /**
     * Состояние сервиса.
     *
     * @return array<string, mixed>
     */
    public function health(): array
    {
        return $this->request('GET', '/api/v1/health');
    }

    /**
     * Запрос к API.
     *
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $payload = null): array
    {
        $url  = $this->baseUrl . $path;
        $body = $payload === null ? '' : (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json; charset=utf-8',
            'Accept: application/json',
        ];

        [$status, $response] = function_exists('curl_init')
            ? $this->viaCurl($method, $url, $headers, $body)
            : $this->viaStream($method, $url, $headers, $body);

        $decoded = json_decode($response, true);
        $decoded = is_array($decoded) ? $decoded : [];

        if ($status >= 200 && $status < 300) {
            return $decoded;
        }

        $message = (string) ($decoded['error']['message'] ?? 'Сервис ответил кодом ' . $status);
        $errors  = (array) ($decoded['error']['details']['errors'] ?? []);

        throw new MailerException($message, $status, $errors, $decoded);
    }

    /**
     * @param array<int, string> $headers
     * @return array{0: int, 1: string}
     */
    private function viaCurl(string $method, string $url, array $headers, string $body): array
    {
        $curl = curl_init($url);
        if ($curl === false) {
            throw new MailerException('Не удалось создать запрос к ' . $url);
        }

        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->timeout,
        ]);

        if ($body !== '') {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($curl);
        $status   = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error    = curl_error($curl);
        curl_close($curl);

        if ($response === false) {
            throw new MailerException('Сервис недоступен: ' . ($error !== '' ? $error : $url));
        }

        return [$status, (string) $response];
    }

    /**
     * @param array<int, string> $headers
     * @return array{0: int, 1: string}
     */
    private function viaStream(string $method, string $url, array $headers, string $body): array
    {
        $context = stream_context_create([
            'http' => [
                'method'        => $method,
                'header'        => implode("\r\n", $headers),
                'content'       => $body,
                'timeout'       => $this->timeout,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        $status   = 0;

        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m) === 1) {
                $status = (int) $m[1];
            }
        }

        if ($response === false && $status === 0) {
            throw new MailerException('Сервис недоступен: ' . $url);
        }

        return [$status, (string) $response];
    }
}
