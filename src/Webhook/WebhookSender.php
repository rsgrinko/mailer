<?php

declare(strict_types=1);

namespace Mailer\Webhook;

use Mailer\Repository\EventRepository;
use Mailer\Repository\WebhookRepository;
use Mailer\Repository\WebhookSubscriptionRepository;
use Mailer\Storage\Database;
use Mailer\Support\Config;
use Mailer\Support\Logger;

/**
 * Доставка вебхуков подписчикам.
 *
 * Заголовки запроса:
 *   X-Mailer-Event      — что случилось (Webhook\Event)
 *   X-Mailer-Delivery   — идентификатор доставки, он же в теле (поле id).
 *                         Повтор приходит с тем же — по нему делается идемпотентность
 *   X-Mailer-Timestamp  — время отправки, unix-секунды
 *   X-Mailer-Attempt    — номер попытки, начиная с единицы
 *   X-Mailer-Signature  — подпись: t=<время>,v1=<hmac-sha256 от «время.тело»>
 *
 * Время входит в подпись, поэтому перехваченный запрос нельзя переиграть через
 * час: принимающая сторона сверяет t с текущим временем и старое отбрасывает.
 * Старые подписки (payload_version = 1) подписываются как раньше — по одному телу,
 * заголовком «sha256=…»: у них на той стороне уже написана проверка.
 */
final class WebhookSender
{
    private WebhookRepository $webhooks;
    private WebhookSubscriptionRepository $subscriptions;
    private EventRepository $events;
    private Logger $logger;

    public function __construct(?Database $db = null)
    {
        $db                  = $db ?? Database::instance();
        $this->webhooks      = new WebhookRepository($db);
        $this->subscriptions = new WebhookSubscriptionRepository($db);
        $this->events        = new EventRepository($db);
        $this->logger        = new Logger('webhook');
    }

    /**
     * Отправляет все вебхуки, которым пришло время. Возвращает количество обработанных.
     */
    public function processQueue(int $limit = 20): int
    {
        $items = $this->webhooks->due($limit);

        foreach ($items as $item) {
            $this->deliver($item);
        }

        return count($items);
    }

    /**
     * Одна попытка доставки.
     *
     * @param array<string, mixed> $item
     */
    public function deliver(array $item): bool
    {
        $id       = (int) $item['id'];
        $attempts = (int) $item['attempts'] + 1;
        $payload  = (string) $item['payload'];

        $subscription = $item['subscription_id'] === null
            ? null
            : $this->subscriptions->find((int) $item['subscription_id']);

        $secret  = $subscription === null ? '' : WebhookSubscriptionRepository::secret($subscription);
        $version = $subscription === null ? Payload::V2 : (int) $subscription['payload_version'];

        $result = $this->post((string) $item['url'], $payload, [
            'event'    => (string) $item['event'],
            'delivery' => (string) ($item['uuid'] ?? ''),
            'attempt'  => $attempts,
            'secret'   => $secret,
            'version'  => $version,
        ]);

        if ($subscription !== null) {
            $this->subscriptions->noteDelivery((int) $subscription['id'], $result['ok'], $result['error'] ?: null);
        }

        if ($result['ok']) {
            $this->webhooks->markDelivered($id, $attempts, $result);
            $this->note($item, 'Вебхук доставлен, ответ ' . $result['code']);

            return true;
        }

        $maxAttempts = (int) Config::get('webhook.max_attempts', 5);
        $retryAt     = null;

        if ($attempts < $maxAttempts) {
            $delays  = (array) Config::get('webhook.backoff', [30, 120, 600, 1800, 7200]);
            $delay   = (int) ($delays[min($attempts - 1, count($delays) - 1)] ?? 600);
            $retryAt = Database::at($delay);
        }

        $this->webhooks->markFailed($id, $attempts, $result['error'], $result, $retryAt);

        $this->logger->warning('Вебхук не доставлен', [
            'url'      => $item['url'],
            'event'    => $item['event'],
            'attempts' => $attempts,
            'error'    => $result['error'],
        ]);

        if ($retryAt === null) {
            $this->note($item, 'Вебхук доставить не удалось: ' . $result['error']);
        }

        return false;
    }

    /**
     * Отметка в истории письма. У проверки связи письма нет — писать некуда.
     *
     * @param array<string, mixed> $item
     */
    private function note(array $item, string $text): void
    {
        if ($item['message_id'] === null) {
            return;
        }

        $this->events->add((int) $item['message_id'], EventRepository::WEBHOOK, $text, [
            'event' => (string) $item['event'],
            'url'   => (string) $item['url'],
        ]);
    }

    /**
     * POST-запрос: через curl, если он есть, иначе обычными потоками.
     *
     * @param array{event: string, delivery: string, attempt: int, secret: string, version: int} $context
     * @return array{ok: bool, code: int, error: string, headers: string, body: string, duration: int, request_headers: string}
     */
    private function post(string $url, string $payload, array $context): array
    {
        $timeout   = (int) Config::get('webhook.timeout', 10);
        $headers   = $this->headers($payload, $context);
        $startedAt = microtime(true);

        if (function_exists('curl_init')) {
            $result = $this->postCurl($url, $payload, $headers, $timeout);
        } else {
            $result = $this->postStream($url, $payload, $headers, $timeout);
        }

        $result['duration']        = (int) round((microtime(true) - $startedAt) * 1000);
        $result['request_headers'] = implode("\n", $headers);

        return $result;
    }

    /**
     * Заголовки запроса вместе с подписью.
     *
     * @param array{event: string, delivery: string, attempt: int, secret: string, version: int} $context
     * @return array<int, string>
     */
    private function headers(string $payload, array $context): array
    {
        $timestamp = time();

        $headers = [
            'Content-Type: application/json; charset=utf-8',
            'User-Agent: Mailer-Webhook/2.0',
            'X-Mailer-Event: ' . $context['event'],
            'X-Mailer-Attempt: ' . $context['attempt'],
            'X-Mailer-Timestamp: ' . $timestamp,
        ];

        if ($context['delivery'] !== '') {
            $headers[] = 'X-Mailer-Delivery: ' . $context['delivery'];
        }

        if ($context['secret'] === '') {
            return $headers;
        }

        // Старым подписчикам — прежняя подпись: у них уже написана её проверка
        $headers[] = $context['version'] === Payload::V1
            ? 'X-Mailer-Signature: sha256=' . hash_hmac('sha256', $payload, $context['secret'])
            : 'X-Mailer-Signature: t=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $payload, $context['secret']);

        return $headers;
    }

    /**
     * @param array<int, string> $headers
     * @return array{ok: bool, code: int, error: string, headers: string, body: string}
     */
    private function postCurl(string $url, string $payload, array $headers, int $timeout): array
    {
        $curl = curl_init($url);
        if ($curl === false) {
            return ['ok' => false, 'code' => 0, 'error' => 'Не удалось создать запрос', 'headers' => '', 'body' => ''];
        }

        curl_setopt_array($curl, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
        ]);

        $response = curl_exec($curl);
        $code     = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $size     = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
        $error    = curl_error($curl);
        curl_close($curl);

        if (!is_string($response)) {
            return [
                'ok'      => false,
                'code'    => $code,
                'error'   => $error !== '' ? $error : 'Нет ответа',
                'headers' => '',
                'body'    => '',
            ];
        }

        return self::answer($code, rtrim(substr($response, 0, $size)), substr($response, $size));
    }

    /**
     * @param array<int, string> $headers
     * @return array{ok: bool, code: int, error: string, headers: string, body: string}
     */
    private function postStream(string $url, string $payload, array $headers, int $timeout): array
    {
        $context = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => implode("\r\n", $headers),
                'content'       => $payload,
                'timeout'       => $timeout,
                'ignore_errors' => true,
            ],
        ]);

        $body     = @file_get_contents($url, false, $context);
        $received = $http_response_header ?? [];
        $code     = 0;

        foreach ($received as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m) === 1) {
                $code = (int) $m[1];
            }
        }

        if ($body === false && $code === 0) {
            return [
                'ok'      => false,
                'code'    => 0,
                'error'   => 'Не удалось выполнить запрос к ' . $url,
                'headers' => '',
                'body'    => '',
            ];
        }

        return self::answer($code, implode("\n", $received), is_string($body) ? $body : '');
    }

    /**
     * Ответ сервера в общем виде: удачным считаем только 2xx.
     *
     * @return array{ok: bool, code: int, error: string, headers: string, body: string}
     */
    private static function answer(int $code, string $headers, string $body): array
    {
        $ok = $code >= 200 && $code < 300;

        return [
            'ok'      => $ok,
            'code'    => $code,
            'error'   => $ok ? '' : 'Сервер ответил ' . $code,
            'headers' => $headers,
            'body'    => $body,
        ];
    }
}
