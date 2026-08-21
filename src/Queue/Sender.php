<?php

declare(strict_types=1);

namespace Mailer\Queue;

use Mailer\Bounce\Unsubscribe;
use Mailer\Bounce\Verp;
use Mailer\Message\Message;
use Mailer\RateLimit\RateLimiter;
use Mailer\Repository\EventRepository;
use Mailer\Repository\MessageRepository;
use Mailer\Repository\ProjectRepository;
use Mailer\Repository\SuppressionRepository;
use Mailer\Repository\TransportRepository;
use Mailer\Storage\Database;
use Mailer\Support\Config;
use Mailer\Support\Logger;
use Mailer\Transport\TransportException;
use Mailer\Transport\TransportFactory;
use Mailer\Transport\TransportInterface;
use Mailer\Webhook\Dispatcher;
use Mailer\Webhook\Event as WebhookEvent;
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
    private Dispatcher $webhooks;
    private TransportFactory $factory;
    private RateLimiter $limiter;
    private SuppressionRepository $suppressions;
    private Logger $logger;

    /**
     * Транспорт, которым отправляли прошлое письмо: SMTP держит в нём открытую
     * сессию, а собирать её заново на каждое письмо дороже самой отправки.
     *
     * @var array{key: string, transport: TransportInterface}|null
     */
    private ?array $opened = null;

    public function __construct(?Database $db = null)
    {
        $this->db           = $db ?? Database::instance();
        $this->messages     = new MessageRepository($this->db);
        $this->events       = new EventRepository($this->db);
        $this->transports   = new TransportRepository($this->db);
        $this->projects     = new ProjectRepository($this->db);
        $this->webhooks     = new Dispatcher($this->db);
        $this->factory      = new TransportFactory($this->transports);
        $this->limiter      = new RateLimiter($this->db);
        $this->suppressions = new SuppressionRepository($this->db);
        $this->logger       = new Logger('sender');
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
            $transportRow  = $resolved['row'];
            $transport     = $this->keepOpen($transportRow, $resolved['transport']);
            $transportName = (string) $transportRow['name'];

            // Суточный лимит транспорта: не ошибка письма, просто отложим
            $limitError = $this->limiter->checkTransport($transportRow);
            if ($limitError !== null) {
                return $this->handleFailure($row, $attempt, TransportException::temporary($limitError), $transportName);
            }

            $this->events->add($id, EventRepository::ATTEMPT, 'Попытка №' . $attempt . ' через «' . $transport->name() . '»');

            $message = $this->messages->toMessage($row);

            // Обратный адрес с идентификатором письма: отказ вернётся на ящик сборщика
            // и сразу привяжется к этому письму
            $returnPath = Verp::address((string) $row['uuid']);
            if ($returnPath !== '') {
                $message->returnPath = $returnPath;
            }

            // Кнопка «отписаться» в почтовом клиенте. Ставим только письму с одним
            // получателем: иначе непонятно, кого отписывать по нажатию
            $recipients = $message->recipients();
            if (count($recipients) === 1 && Unsubscribe::enabled($project)) {
                $message->headers = array_merge(
                    $message->headers,
                    Unsubscribe::headers($recipients[0], (int) $project['id'])
                );
            }

            $info = $transport->send($message);

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
                        'Отправитель заменён транспортом: ' . $replaced['was'] . ' → ' . $replaced['now'],
                        $replaced
                    );
                }

                $this->events->add($id, EventRepository::SENT, $info);
                $this->transports->markUsed((int) $transportRow['id'], null);
                $this->limiter->hitTransport((int) $transportRow['id']);
                // В теле вебхука письмо должно быть уже отправленным, а не таким,
                // каким мы его достали из очереди
                $this->webhooks->message(WebhookEvent::MESSAGE_SENT, array_merge($row, [
                    'status'         => MessageRepository::SENT,
                    'attempts'       => $attempt,
                    'transport_used' => $transport->name(),
                    'sender_used'    => $sender,
                    'sent_at'        => Database::now(),
                ]), ['info' => $info]);
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
    /**
     * Закрывает открытую сессию транспорта. Воркер зовёт это, когда очередь опустела:
     * держать соединение впустую незачем, сервер всё равно его скоро оборвёт.
     */
    public function closeTransports(): void
    {
        if ($this->opened !== null) {
            $this->opened['transport']->close();
        }

        $this->opened = null;
    }

    /**
     * Синхронная отправка живёт один запрос: прощаемся с сервером сами,
     * а не бросаем соединение открытым.
     */
    public function __destruct()
    {
        $this->closeTransports();
    }

    /**
     * Тот же транспорт, что и в прошлый раз, — если настройки не менялись.
     *
     * Держим ровно один: письма в очереди идут вперемешку, и разводить открытые
     * соединения на все транспорты сразу незачем.
     *
     * @param array<string, mixed> $row
     */
    private function keepOpen(array $row, TransportInterface $fresh): TransportInterface
    {
        $key = $this->transportKey($row);

        if ($this->opened !== null && $this->opened['key'] === $key) {
            return $this->opened['transport'];
        }

        $this->closeTransports();
        $this->opened = ['key' => $key, 'transport' => $fresh];

        return $fresh;
    }

    /**
     * Отпечаток настроек транспорта: поправили их в панели — прошлая сессия не подойдёт.
     * Отметки о последней отправке в него не входят, иначе он менялся бы каждое письмо.
     *
     * @param array<string, mixed> $row
     */
    private function transportKey(array $row): string
    {
        unset($row['last_used_at'], $row['last_error'], $row['updated_at']);

        return md5(serialize($row));
    }

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

            $this->webhooks->message(WebhookEvent::MESSAGE_RETRY, array_merge($row, [
                'status'   => MessageRepository::QUEUED,
                'attempts' => $attempt,
            ]), ['error' => $error, 'attempt' => $attempt, 'next_try_in' => $delay]);

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

            $this->webhooks->message(WebhookEvent::MESSAGE_FAILED, array_merge($row, [
                'status'   => MessageRepository::FAILED,
                'attempts' => $attempt,
            ]), ['error' => $error, 'attempts' => $attempt]);

            $this->suppressBounced($row, $e);
        });

        $this->logger->error('Письмо не отправлено', [
            'uuid'    => $row['uuid'],
            'attempt' => $attempt,
            'error'   => $error,
        ]);

        return ['status' => MessageRepository::FAILED, 'info' => $error];
    }

    /**
     * Сервер отказал по конкретному ящику — закрываем адрес, чтобы не долбиться в него
     * каждым следующим письмом.
     *
     * Блокируем не любой 5xx: какой ответ считать отказом по ящику, решает
     * SuppressionRepository::isHardBounce().
     *
     * @param array<string, mixed> $row
     */
    private function suppressBounced(array $row, TransportException $e): void
    {
        if (!(bool) Config::get('suppression.auto_bounce', true) || $e->isTemporary()) {
            return;
        }

        $context   = $e->getContext();
        $recipient = trim((string) ($context['recipient'] ?? ''));

        if ($recipient === '') {
            return;
        }

        $answer = (string) ($context['answer'] ?? $e->getMessage());
        if (!SuppressionRepository::isHardBounce($answer)) {
            return;
        }

        $this->suppressions->block($recipient, SuppressionRepository::BOUNCE, 'bounce', [
            'message_id' => (int) $row['id'],
            'owner_id'   => (int) ($row['owner_id'] ?? 0),
            'note'       => $answer,
        ]);

        $this->events->add(
            (int) $row['id'],
            EventRepository::SUPPRESSED,
            'Адрес ' . $recipient . ' закрыт стоп-листом: сервер получателя ответил отказом',
            ['recipient' => $recipient, 'answer' => $answer]
        );

        $this->webhooks->message(WebhookEvent::MESSAGE_BOUNCED, $row, [
            'recipient' => $recipient,
            'answer'    => $answer,
            'permanent' => true,
        ]);

        $this->logger->warning('Адрес добавлен в стоп-лист по отказу сервера', [
            'recipient' => $recipient,
            'answer'    => $answer,
            'uuid'      => $row['uuid'] ?? '',
        ]);
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
}
