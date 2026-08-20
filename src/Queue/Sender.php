<?php

declare(strict_types=1);

namespace Mailer\Queue;

use Mailer\Message\Message;
use Mailer\RateLimit\RateLimiter;
use Mailer\Repository\EventRepository;
use Mailer\Repository\MessageRepository;
use Mailer\Repository\ProjectRepository;
use Mailer\Repository\TransportRepository;
use Mailer\Repository\WebhookRepository;
use Mailer\Storage\Database;
use Mailer\Support\Config;
use Mailer\Support\Logger;
use Mailer\Transport\TransportException;
use Mailer\Transport\TransportFactory;
use Throwable;

/**
 * Отправка одного письма: выбрать транспорт, попробовать отправить,
 * записать результат и решить, нужен ли повтор.
 *
 * Используется и воркером, и синхронной отправкой через API.
 */
final class Sender
{
    private Database $db;
    private MessageRepository $messages;
    private EventRepository $events;
    private TransportRepository $transports;
    private ProjectRepository $projects;
    private WebhookRepository $webhooks;
    private TransportFactory $factory;
    private RateLimiter $limiter;
    private Logger $logger;

    public function __construct(?Database $db = null)
    {
        $this->db         = $db ?? Database::instance();
        $this->messages   = new MessageRepository($this->db);
        $this->events     = new EventRepository($this->db);
        $this->transports = new TransportRepository($this->db);
        $this->projects   = new ProjectRepository($this->db);
        $this->webhooks   = new WebhookRepository($this->db);
        $this->factory    = new TransportFactory($this->transports);
        $this->limiter    = new RateLimiter($this->db);
        $this->logger     = new Logger('sender');
    }

    /**
     * Отправляет письмо по строке из базы.
     *
     * @param array<string, mixed> $row
     * @return array{status: string, info: string}
     */
    public function send(array $row): array
    {
        $id      = (int) $row['id'];
        $attempt = (int) $row['attempts'] + 1;

        // Запоминаем сразу: если отправка сорвётся, ошибку нужно записать транспорту
        $transportName = null;

        try {
            $project = $row['project_id'] !== null ? $this->projects->find((int) $row['project_id']) : null;

            $resolved      = $this->factory->resolve(
                $row['transport_id'] !== null ? (int) $row['transport_id'] : null,
                $project
            );
            $transport     = $resolved['transport'];
            $transportRow  = $resolved['row'];
            $transportName = (string) $transportRow['name'];

            // Суточный лимит транспорта: не ошибка письма, просто отложим
            $limitError = $this->limiter->checkTransport($transportRow);
            if ($limitError !== null) {
                return $this->handleFailure($row, $attempt, TransportException::temporary($limitError), $transportName);
            }

            $this->events->add($id, EventRepository::ATTEMPT, 'Попытка №' . $attempt . ' через «' . $transport->name() . '»');

            $message = $this->messages->toMessage($row);
            $info    = $transport->send($message);

            // Транспорт мог подставить свой адрес вместо указанного в письме
            $sender   = trim((string) ($message->from?->email ?? '')) ?: null;
            $replaced = $this->senderReplaced($row, $message);

            // Письмо уже ушло, откатывать нечего — но записать об этом нужно всё разом:
            // статус, событие, отметку транспорта и вебхук
            $this->db->transaction(function () use ($id, $attempt, $transportRow, $transport, $info, $row, $replaced, $sender): void {
                $this->messages->update($id, [
                    'status'         => MessageRepository::SENT,
                    'attempts'       => $attempt,
                    'transport_id'   => (int) $transportRow['id'],
                    'transport_used' => $transport->name(),
                    'sender_used'    => $sender,
                    'sent_at'        => Database::now(),
                    'locked_by'      => null,
                    'locked_at'      => null,
                    'last_error'     => null,
                ]);

                if ($replaced !== null) {
                    $this->events->add(
                        $id,
                        EventRepository::SENDER,
                        'Отправитель заменён транспортом: ' . $replaced['was'] . ' -> ' . $replaced['now'],
                        $replaced
                    );
                }

                $this->events->add($id, EventRepository::SENT, $info);
                $this->transports->markUsed((int) $transportRow['id'], null);
                $this->limiter->hitTransport((int) $transportRow['id']);
                $this->queueWebhook($row, 'sent', ['info' => $info, 'transport' => $transport->name()]);
            });

            $this->logger->info('Письмо отправлено', [
                'uuid'      => $row['uuid'],
                'transport' => $transport->name(),
                'attempt'   => $attempt,
            ]);

            return ['status' => MessageRepository::SENT, 'info' => $info];
        } catch (TransportException $e) {
            return $this->handleFailure($row, $attempt, $e, $transportName);
        } catch (Throwable $e) {
            // Любая другая ошибка (например, кривые настройки) — считаем окончательной
            return $this->handleFailure($row, $attempt, TransportException::permanent($e->getMessage(), [], $e), $transportName);
        }
    }

    /**
     * Транспорт с force_from подменяет отправителя уже на отправке, в базе остаётся
     * исходный. Сравниваем адреса и, если они разошлись, отдаём оба — иначе потом
     * не понять, почему письмо ушло не с того адреса.
     *
     * @param array<string, mixed> $row
     * @return array{was: string, now: string}|null
     */
    private function senderReplaced(array $row, Message $message): ?array
    {
        $was = trim((string) ($row['from_email'] ?? ''));
        $now = trim((string) ($message->from?->email ?? ''));

        if ($was === '' || $now === '' || strcasecmp($was, $now) === 0) {
            return null;
        }

        return ['was' => $was, 'now' => $now];
    }

    /**
     * Обрабатывает неудачу: либо назначаем повтор, либо признаём письмо неотправленным.
     *
     * @param array<string, mixed> $row
     * @return array{status: string, info: string}
     */
    private function handleFailure(array $row, int $attempt, TransportException $e, ?string $transportName): array
    {
        $id          = (int) $row['id'];
        $maxAttempts = (int) $row['max_attempts'];
        $error       = $e->getMessage();

        $canRetry = $e->isTemporary() && $attempt < $maxAttempts;

        if ($canRetry) {
            $delay = $this->backoff($attempt);

            $this->messages->update($id, [
                'status'       => MessageRepository::QUEUED,
                'attempts'     => $attempt,
                'available_at' => Database::at($delay),
                'locked_by'    => null,
                'locked_at'    => null,
                'last_error'   => $error,
            ]);

            $this->events->add($id, EventRepository::RETRY, $error, [
                'attempt'    => $attempt,
                'next_try_in' => $delay,
            ]);

            $this->logger->warning('Отправка не удалась, попробуем позже', [
                'uuid'    => $row['uuid'],
                'attempt' => $attempt,
                'delay'   => $delay,
                'error'   => $error,
            ]);

            return ['status' => MessageRepository::QUEUED, 'info' => $error];
        }

        // Отметка о неудаче, событие, ошибка транспорта и вебхук — одной транзакцией
        $this->db->transaction(function () use ($id, $attempt, $error, $e, $transportName, $row): void {
            $this->messages->update($id, [
                'status'     => MessageRepository::FAILED,
                'attempts'   => $attempt,
                'locked_by'  => null,
                'locked_at'  => null,
                'last_error' => $error,
            ]);

            $this->events->add($id, EventRepository::FAILED, $error, [
                'attempt'   => $attempt,
                'temporary' => $e->isTemporary(),
                'context'   => $e->getContext(),
            ]);

            if ($transportName !== null) {
                $transportRow = $this->transports->findByName($transportName);
                if ($transportRow !== null) {
                    $this->transports->markUsed((int) $transportRow['id'], $error);
                }
            }

            $this->queueWebhook($row, 'failed', ['error' => $error, 'attempts' => $attempt]);
        });

        $this->logger->error('Письмо не отправлено', [
            'uuid'    => $row['uuid'],
            'attempt' => $attempt,
            'error'   => $error,
        ]);

        return ['status' => MessageRepository::FAILED, 'info' => $error];
    }

    /**
     * Через сколько секунд повторить попытку.
     */
    private function backoff(int $attempt): int
    {
        $delays = (array) Config::get('queue.backoff', [60, 300, 900, 3600, 10800]);
        $index  = min($attempt - 1, count($delays) - 1);

        return (int) ($delays[max(0, $index)] ?? 300);
    }

    /**
     * Ставит вебхук в очередь, если у проекта указан адрес.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $extra
     */
    private function queueWebhook(array $row, string $event, array $extra = []): void
    {
        if ($row['project_id'] === null) {
            return;
        }

        $project = $this->projects->find((int) $row['project_id']);
        if ($project === null || ($project['webhook_url'] ?? '') === '') {
            return;
        }

        $this->webhooks->enqueue(
            (int) $project['id'],
            (int) $row['id'],
            (string) $project['webhook_url'],
            $event,
            array_merge([
                'event'      => $event,
                'message_id' => (string) $row['uuid'],
                'status'     => $event === 'sent' ? MessageRepository::SENT : MessageRepository::FAILED,
                'subject'    => $row['subject'],
                'to'         => array_column($this->messages->decodeArray($row['to_json'] ?? null), 'email'),
                'tag'        => $row['tag'],
                'timestamp'  => time(),
            ], $extra)
        );
    }
}
