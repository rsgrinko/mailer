<?php

declare(strict_types=1);

namespace Mailer\Webhook;

use Mailer\Repository\EventRepository;
use Mailer\Repository\ProjectRepository;
use Mailer\Repository\WebhookRepository;
use Mailer\Storage\Database;
use Mailer\Support\Config;
use Mailer\Support\Logger;

/**
 * Доставка вебхуков проекту. Тело подписываем HMAC-SHA256, чтобы принимающая
 * сторона могла убедиться, что запрос пришёл от нас.
 *
 * Заголовки запроса:
 *   X-Mailer-Event      — событие (sent, failed)
 *   X-Mailer-Signature  — sha256=<подпись тела>
 */
final class WebhookSender
{
    private WebhookRepository $webhooks;
    private ProjectRepository $projects;
    private EventRepository $events;
    private Logger $logger;

    public function __construct(?Database $db = null)
    {
        $db             = $db ?? Database::instance();
        $this->webhooks = new WebhookRepository($db);
        $this->projects = new ProjectRepository($db);
        $this->events   = new EventRepository($db);
        $this->logger   = new Logger('webhook');
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
        $secret   = '';

        if ($item['project_id'] !== null) {
            $project = $this->projects->find((int) $item['project_id']);
            $secret  = (string) ($project['webhook_secret'] ?? '');
        }

        $result = $this->post((string) $item['url'], $payload, (string) $item['event'], $secret);

        if ($result['ok']) {
            $this->webhooks->markDelivered($id, $result['code']);

            if ($item['message_id'] !== null) {
                $this->events->add(
                    (int) $item['message_id'],
                    EventRepository::WEBHOOK,
                    'Вебхук доставлен, ответ ' . $result['code']
                );
            }

            return true;
        }

        $maxAttempts = (int) Config::get('webhook.max_attempts', 5);
        $retryAt     = null;

        if ($attempts < $maxAttempts) {
            $delays  = (array) Config::get('webhook.backoff', [30, 120, 600, 1800, 7200]);
            $delay   = (int) ($delays[min($attempts - 1, count($delays) - 1)] ?? 600);
            $retryAt = Database::at($delay);
        }

        $this->webhooks->markFailed($id, $attempts, $result['error'], $result['code'] > 0 ? $result['code'] : null, $retryAt);

        $this->logger->warning('Вебхук не доставлен', [
            'url'      => $item['url'],
            'attempts' => $attempts,
            'error'    => $result['error'],
        ]);

        if ($item['message_id'] !== null && $retryAt === null) {
            $this->events->add(
                (int) $item['message_id'],
                EventRepository::WEBHOOK,
                'Вебхук доставить не удалось: ' . $result['error']
            );
        }

        return false;
    }

    /**
     * POST-запрос: через curl, если он есть, иначе обычными потоками.
     *
     * @return array{ok: bool, code: int, error: string}
     */
    private function post(string $url, string $payload, string $event, string $secret): array
    {
        $timeout = (int) Config::get('webhook.timeout', 10);

        $headers = [
            'Content-Type: application/json; charset=utf-8',
            'User-Agent: Mailer-Webhook/1.0',
            'X-Mailer-Event: ' . $event,
        ];

        if ($secret !== '') {
            $headers[] = 'X-Mailer-Signature: sha256=' . hash_hmac('sha256', $payload, $secret);
        }

        if (function_exists('curl_init')) {
            $curl = curl_init($url);
            if ($curl === false) {
                return ['ok' => false, 'code' => 0, 'error' => 'Не удалось создать запрос'];
            }

            curl_setopt_array($curl, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => $timeout,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
            ]);

            $body  = curl_exec($curl);
            $code  = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $error = curl_error($curl);
            curl_close($curl);

            if ($body === false) {
                return ['ok' => false, 'code' => $code, 'error' => $error !== '' ? $error : 'Нет ответа'];
            }

            return [
                'ok'    => $code >= 200 && $code < 300,
                'code'  => $code,
                'error' => $code >= 200 && $code < 300 ? '' : 'Сервер ответил ' . $code,
            ];
        }

        $context = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => implode("\r\n", $headers),
                'content'       => $payload,
                'timeout'       => $timeout,
                'ignore_errors' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        $code = 0;

        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m) === 1) {
                $code = (int) $m[1];
            }
        }

        if ($body === false && $code === 0) {
            return ['ok' => false, 'code' => 0, 'error' => 'Не удалось выполнить запрос к ' . $url];
        }

        return [
            'ok'    => $code >= 200 && $code < 300,
            'code'  => $code,
            'error' => $code >= 200 && $code < 300 ? '' : 'Сервер ответил ' . $code,
        ];
    }
}
