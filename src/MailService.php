<?php

declare(strict_types=1);

namespace Mailer;

use Mailer\Message\MessageFactory;
use Mailer\Queue\Queue;
use Mailer\Queue\Sender;
use Mailer\Repository\MessageRepository;
use Mailer\Repository\TransportRepository;
use Mailer\Storage\Database;
use Mailer\Support\MailerException;

/**
 * Точка входа для всех способов отправки: HTTP API, CLI, sendmail-shim, SMTP-релей и панель.
 * Здесь письмо собирается, кладётся в очередь и, если попросили, сразу отправляется.
 */
final class MailService
{
    private MessageFactory $factory;
    private Queue $queue;
    private Sender $sender;
    private MessageRepository $messages;
    private TransportRepository $transports;

    public function __construct(?Database $db = null)
    {
        $db = $db ?? Database::instance();

        $this->factory    = new MessageFactory();
        $this->queue      = new Queue($db);
        $this->sender     = new Sender($db);
        $this->messages   = new MessageRepository($db);
        $this->transports = new TransportRepository($db);
    }

    /**
     * Принимает письмо от клиента.
     *
     * @param array<string, mixed> $payload данные письма
     * @param array<string, mixed>|null $project проект-отправитель (для API-ключа)
     * @param string $source откуда пришло письмо: api, sendmail, smtpd, cli, ui
     * @return array<string, mixed> сведения о принятом письме
     */
    public function accept(array $payload, ?array $project = null, string $source = MessageRepository::SOURCE_API): array
    {
        $built   = $this->factory->build($payload, $project);
        $message = $built['message'];
        $options = $built['options'];

        // Клиент может попросить конкретный транспорт по имени
        $transportId = null;
        if (!empty($options['transport'])) {
            $transport = $this->transports->findByName((string) $options['transport']);
            if ($transport === null) {
                throw new MailerException('Транспорт «' . $options['transport'] . '» не найден', [], 422);
            }
            $transportId = (int) $transport['id'];
        }

        $result = $this->queue->push($message, [
            'project'         => $project,
            'source'          => $source,
            'transport_id'    => $transportId,
            'template'        => $options['template'] ?? null,
            'template_data'   => $options['template_data'] ?? [],
            'idempotency_key' => $options['idempotency_key'] ?? null,
            'send_at'         => $options['send_at'] ?? null,
            'priority'        => $options['priority'] ?? 100,
        ]);

        // Синхронный режим: отправляем прямо сейчас и возвращаем результат
        $sync = (bool) ($payload['sync'] ?? false);
        if ($sync && !$result['duplicate']) {
            $sent = $this->sendNow((int) $result['id']);

            return array_merge($result, [
                'status' => $sent['status'],
                'info'   => $sent['info'],
                'sync'   => true,
            ]);
        }

        return array_merge($result, ['sync' => false]);
    }

    /**
     * Отправляет письмо немедленно (минуя воркер).
     *
     * @return array{status: string, info: string}
     */
    public function sendNow(int $id): array
    {
        $row = $this->messages->find($id);
        if ($row === null) {
            throw new MailerException('Письмо не найдено: id=' . $id, [], 404);
        }

        if ((string) $row['status'] === MessageRepository::SENT) {
            return ['status' => MessageRepository::SENT, 'info' => 'Письмо уже отправлено'];
        }

        return $this->sender->send($row);
    }

    public function queue(): Queue
    {
        return $this->queue;
    }

    public function messages(): MessageRepository
    {
        return $this->messages;
    }
}
