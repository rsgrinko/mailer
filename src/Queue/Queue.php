<?php

declare(strict_types=1);

namespace Mailer\Queue;

use Mailer\Message\Message;
use Mailer\RateLimit\RateLimiter;
use Mailer\Repository\EventRepository;
use Mailer\Repository\MessageRepository;
use Mailer\Repository\SuppressionRepository;
use Mailer\Storage\Database;
use Mailer\Support\Config;
use Mailer\Support\Logger;
use Mailer\Support\MailerException;
use Mailer\Webhook\Dispatcher;
use Mailer\Webhook\Event as WebhookEvent;

/**
 * Очередь писем: приём, выдача воркеру, повторы и отмена.
 */
final class Queue
{
    private Database $db;
    private MessageRepository $messages;
    private EventRepository $events;
    private RateLimiter $limiter;
    private SuppressionRepository $suppressions;
    private Dispatcher $webhooks;
    private Logger $logger;

    public function __construct(
        ?Database $db = null,
        ?MessageRepository $messages = null,
        ?EventRepository $events = null,
        ?RateLimiter $limiter = null
    ) {
        $this->db           = $db ?? Database::instance();
        $this->messages     = $messages ?? new MessageRepository($this->db);
        $this->events       = $events ?? new EventRepository($this->db);
        $this->limiter      = $limiter ?? new RateLimiter($this->db);
        $this->suppressions = new SuppressionRepository($this->db);
        $this->webhooks     = new Dispatcher($this->db);
        $this->logger       = new Logger('queue');
    }

    /**
     * Принимает письмо в очередь.
     *
     * @param array<string, mixed> $options project, owner_id, source, transport_id, template,
     *                                      template_data, idempotency_key, send_at, max_attempts
     * @return array{id: int, uuid: string, status: string, duplicate: bool}
     */
    public function push(Message $message, array $options = []): array
    {
        $project   = $options['project'] ?? null;
        $projectId = $project !== null ? (int) $project['id'] : null;

        // Повторный запрос с тем же ключом — отдаём то, что уже приняли
        $idempotencyKey = $options['idempotency_key'] ?? null;
        if ($projectId !== null && is_string($idempotencyKey) && $idempotencyKey !== '') {
            $existing = $this->messages->findByIdempotencyKey($projectId, $idempotencyKey);
            if ($existing !== null) {
                return [
                    'id'        => (int) $existing['id'],
                    'uuid'      => (string) $existing['uuid'],
                    'status'    => (string) $existing['status'],
                    'duplicate' => true,
                ];
            }
        }

        // Лимиты проекта
        if ($project !== null) {
            $error = $this->limiter->checkProject($project);
            if ($error !== null) {
                throw new MailerException($error, ['project' => $project['name'] ?? ''], 429);
            }
        }

        // Стоп-лист: закрытые адреса убираем из письма ещё до записи в базу.
        // Не осталось ни одного получателя — письмо сохраняем, но отправлять нечего
        $suppressed = $this->suppressed($message, $projectId);
        $status     = $message->recipients() === [] ? MessageRepository::SUPPRESSED : MessageRepository::QUEUED;

        $uuid = \Mailer\Support\Str::uuid();

        // Вложения кладём на диск: в базе хранить их незачем
        $this->storeAttachments($message, $uuid);

        $availableAt = Database::now();
        if (!empty($options['send_at'])) {
            $timestamp = strtotime((string) $options['send_at']);
            if ($timestamp === false) {
                throw new MailerException('Не удалось разобрать дату отправки: ' . $options['send_at']);
            }
            $availableAt = date('Y-m-d H:i:s', $timestamp);
        }

        $id = $this->messages->store($message, [
            'uuid'            => $uuid,
            'project_id'      => $projectId,
            'owner_id'        => (int) ($options['owner_id'] ?? ($project['owner_id'] ?? 0)),
            'transport_id'    => $options['transport_id'] ?? null,
            'source'          => $options['source'] ?? MessageRepository::SOURCE_API,
            'status'          => $status,
            'available_at'    => $availableAt,
            'max_attempts'    => (int) ($options['max_attempts'] ?? Config::get('queue.max_attempts', 5)),
            'template'        => $options['template'] ?? null,
            'template_data'   => (array) ($options['template_data'] ?? []),
            'idempotency_key' => is_string($idempotencyKey) && $idempotencyKey !== '' ? $idempotencyKey : null,
            'priority'        => (int) ($options['priority'] ?? $message->priority),
        ]);

        $this->events->add($id, EventRepository::ACCEPTED, 'Письмо принято в очередь', [
            'source'     => $options['source'] ?? MessageRepository::SOURCE_API,
            'recipients' => $message->recipients(),
        ]);

        if ($suppressed !== []) {
            $this->events->add(
                $id,
                EventRepository::SUPPRESSED,
                $status === MessageRepository::SUPPRESSED
                    ? 'Все получатели в стоп-листе, письмо не отправляется'
                    : 'Часть получателей в стоп-листе, им письмо не уйдёт',
                ['recipients' => $suppressed]
            );

            $this->logger->info('Письмо задето стоп-листом', [
                'uuid'       => $uuid,
                'recipients' => array_keys($suppressed),
                'status'     => $status,
            ]);
        }

        if ($projectId !== null) {
            $this->limiter->hitProject($projectId);
        }

        // Вебхуки шлём по строке из базы, а не по объекту письма: у подписчика
        // должно быть то же самое, что и в карточке письма
        $stored = $this->messages->find($id);
        if ($stored !== null) {
            $this->webhooks->message(WebhookEvent::MESSAGE_QUEUED, $stored, [
                'source' => (string) ($options['source'] ?? MessageRepository::SOURCE_API),
            ]);

            if ($suppressed !== []) {
                $this->webhooks->message(WebhookEvent::MESSAGE_SUPPRESSED, $stored, [
                    'recipients' => $suppressed,
                    'all'        => $status === MessageRepository::SUPPRESSED,
                ]);
            }
        }

        $this->logger->info('Письмо принято', [
            'uuid'    => $uuid,
            'subject' => $message->subject,
            'to'      => $message->recipients(),
        ]);

        return ['id' => $id, 'uuid' => $uuid, 'status' => $status, 'duplicate' => false];
    }

    /**
     * Убирает из письма адреса, закрытые стоп-листом, и возвращает их с причиной.
     * Пустой список получателей после этого означает, что отправлять некому.
     *
     * @return array<string, string> адрес => причина
     */
    private function suppressed(Message $message, ?int $projectId): array
    {
        $blocked = $this->suppressions->blocked($message->recipients(), $projectId);

        if ($blocked === []) {
            return [];
        }

        $keep = static fn (array $addresses): array => array_values(array_filter(
            $addresses,
            static fn ($address): bool => !isset($blocked[SuppressionRepository::normalize($address->email)])
        ));

        $message->to  = $keep($message->to);
        $message->cc  = $keep($message->cc);
        $message->bcc = $keep($message->bcc);

        // У писем из sendmail и SMTP-релея получатели заданы конвертом
        $message->envelopeTo = array_values(array_filter(
            $message->envelopeTo,
            static fn (string $email): bool => !isset($blocked[SuppressionRepository::normalize($email)])
        ));

        $reasons = [];
        foreach ($blocked as $email => $row) {
            $reasons[(string) $email] = (string) $row['reason'];
        }

        return $reasons;
    }

    /**
     * Забирает пачку писем на отправку и помечает их как «в работе».
     *
     * @return array<int, array<string, mixed>>
     */
    public function claim(int $limit, string $workerId): array
    {
        $limit = max(1, $limit);

        return $this->db->transaction(function (Database $db) use ($limit, $workerId): array {
            $sql = 'SELECT id FROM messages
                    WHERE status = :status AND (available_at IS NULL OR available_at <= :now)
                    ORDER BY priority, id LIMIT ' . $limit;

            // В MySQL пропускаем строки, которые уже взял другой воркер
            if (!$db->isSqlite()) {
                $sql .= ' FOR UPDATE SKIP LOCKED';
            }

            $rows = $db->select($sql, ['status' => MessageRepository::QUEUED, 'now' => Database::now()]);
            if ($rows === []) {
                return [];
            }

            $ids    = array_map(static fn (array $row): int => (int) $row['id'], $rows);
            $inList = implode(', ', $ids);

            $db->execute(
                'UPDATE messages SET status = :sending, locked_by = :worker, locked_at = :now, updated_at = :now2
                 WHERE id IN (' . $inList . ') AND status = :queued',
                [
                    'sending' => MessageRepository::SENDING,
                    'worker'  => $workerId,
                    'now'     => Database::now(),
                    'now2'    => Database::now(),
                    'queued'  => MessageRepository::QUEUED,
                ]
            );

            return $db->select(
                'SELECT * FROM messages WHERE id IN (' . $inList . ') AND locked_by = :worker AND status = :status',
                ['worker' => $workerId, 'status' => MessageRepository::SENDING]
            );
        });
    }

    /**
     * Захватить одно конкретное письмо — для отправки прямо сейчас: из панели
     * («отправить сейчас») или синхронным запросом API.
     *
     * Без этого захвата письмо остаётся в очереди всё время, пока идёт отправка,
     * и фоновый воркер спокойно берёт его вторым — получатель получает дубль.
     * Возвращает строку письма, если захватить удалось, и null, если письмо уже
     * кто-то отправляет или его статус сменился.
     *
     * @return array<string, mixed>|null
     */
    public function claimOne(int $id, string $owner): ?array
    {
        return $this->db->transaction(function (Database $db) use ($id, $owner): ?array {
            $updated = $db->execute(
                'UPDATE messages SET status = :sending, locked_by = :owner, locked_at = :now, updated_at = :now2
                 WHERE id = :id AND status IN (:queued, :failed, :canceled)',
                [
                    'sending'  => MessageRepository::SENDING,
                    'owner'    => $owner,
                    'now'      => Database::now(),
                    'now2'     => Database::now(),
                    'id'       => $id,
                    'queued'   => MessageRepository::QUEUED,
                    'failed'   => MessageRepository::FAILED,
                    'canceled' => MessageRepository::CANCELED,
                ]
            );

            if ($updated === 0) {
                return null;
            }

            return $db->selectOne('SELECT * FROM messages WHERE id = :id', ['id' => $id]);
        });
    }

    /**
     * Возвращает в очередь письма, которые зависли в статусе «отправляется»
     * (например, воркера убили посреди работы).
     */
    public function requeueStuck(): int
    {
        $border = Database::at(-1 * (int) Config::get('queue.stuck_after', 900));

        $rows = $this->db->select(
            'SELECT id FROM messages WHERE status = :status AND locked_at < :border',
            ['status' => MessageRepository::SENDING, 'border' => $border]
        );

        foreach ($rows as $row) {
            $id = (int) $row['id'];

            $this->messages->update($id, [
                'status'       => MessageRepository::QUEUED,
                'locked_by'    => null,
                'locked_at'    => null,
                'available_at' => Database::now(),
            ]);

            $this->events->add($id, EventRepository::REQUEUED, 'Письмо зависло в отправке и возвращено в очередь');
        }

        return count($rows);
    }

    /**
     * Повторить письмо вручную (из панели, CLI или API).
     */
    public function retry(int $id, string $reason = 'Повтор запрошен вручную'): bool
    {
        $row = $this->messages->find($id);
        if ($row === null) {
            return false;
        }

        // Отправленное письмо повторять нельзя: получатель получит дубль.
        // Если нужно такое же письмо — делается копия, а это письмо остаётся как есть.
        if ((string) $row['status'] === MessageRepository::SENT) {
            return false;
        }

        $this->messages->update($id, [
            'status'       => MessageRepository::QUEUED,
            'available_at' => Database::now(),
            'locked_by'    => null,
            'locked_at'    => null,
            'last_error'   => null,
            // Письмо снова ждёт отправки — прежняя отметка об отправке уже не про него
            'sent_at'      => null,
            // Даём письму ещё попыток, иначе оно снова упрётся в лимит
            'max_attempts' => max(
                (int) $row['max_attempts'],
                (int) $row['attempts'] + (int) Config::get('queue.max_attempts', 5)
            ),
        ]);

        $this->events->add($id, EventRepository::RETRY, $reason);

        return true;
    }

    /**
     * Отменить письмо, пока оно не ушло.
     */
    public function cancel(int $id, string $reason = 'Отменено вручную'): bool
    {
        $row = $this->messages->find($id);
        if ($row === null) {
            return false;
        }

        if (in_array((string) $row['status'], [MessageRepository::SENT, MessageRepository::CANCELED], true)) {
            return false;
        }

        $this->messages->update($id, [
            'status'    => MessageRepository::CANCELED,
            'locked_by' => null,
            'locked_at' => null,
        ]);

        $this->events->add($id, EventRepository::CANCELED, $reason);

        $this->webhooks->message(WebhookEvent::MESSAGE_CANCELED, array_merge($row, [
            'status' => MessageRepository::CANCELED,
        ]), ['reason' => $reason]);

        return true;
    }

    /**
     * Сколько писем ждёт отправки прямо сейчас.
     */
    public function readyCount(): int
    {
        return (int) $this->db->value(
            'SELECT COUNT(*) FROM messages WHERE status = :status AND (available_at IS NULL OR available_at <= :now)',
            ['status' => MessageRepository::QUEUED, 'now' => Database::now()]
        );
    }

    /**
     * Сохраняет вложения письма в var/spool, чтобы база не распухала.
     */
    private function storeAttachments(Message $message, string $uuid): void
    {
        if ($message->attachments === []) {
            return;
        }

        $dir = rtrim((string) Config::get('paths.spool', MAILER_ROOT . '/var/spool'), '/') . '/attachments/' . $uuid;

        foreach ($message->attachments as $index => $attachment) {
            $safeName = preg_replace('/[^\w.\-]+/u', '_', $attachment->name) ?? 'file';
            $attachment->moveToFile($dir . '/' . $index . '-' . $safeName);
        }
    }
}
