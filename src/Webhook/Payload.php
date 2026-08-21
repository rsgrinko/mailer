<?php

declare(strict_types=1);

namespace Mailer\Webhook;

use Mailer\Repository\MessageRepository;

/**
 * Тело вебхука. Формат один на все события: снаружи конверт с тем, что и когда
 * случилось, внутри — данные события. Раньше поля собирались на месте отправки
 * и у каждого события набор был свой — разобрать такое на стороне клиента можно
 * было только по факту.
 *
 * Конверт:
 *   id           — идентификатор доставки, он же в заголовке X-Mailer-Delivery.
 *                  Повтор приходит с тем же id: по нему делается идемпотентность
 *   event        — что случилось (Webhook\Event)
 *   occurred_at  — когда, ISO 8601 с часовым поясом
 *   project      — чей это вебхук
 *   data         — данные события: письмо и то, что относится к самому событию
 *
 * Версия 1 — прежний плоский вид. Его продолжают получать подписки, заведённые
 * до конверта; новые заводятся со второй версией.
 */
final class Payload
{
    public const V1 = 1;
    public const V2 = 2;

    /**
     * Конверт второй версии.
     *
     * @param array<string, mixed> $project
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function envelope(string $deliveryUuid, string $event, array $project, array $data): array
    {
        return [
            'id'          => $deliveryUuid,
            'event'       => $event,
            'occurred_at' => date('c'),
            'project'     => [
                'id'   => (int) ($project['id'] ?? 0),
                'name' => (string) ($project['name'] ?? ''),
            ],
            'data' => $data,
        ];
    }

    /**
     * Данные письма для тела вебхука. Набор полей один у всех событий: клиенту
     * не приходится гадать, что придёт именно с этим.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function message(array $row, string $event): array
    {
        return [
            'id'         => (string) ($row['uuid'] ?? ''),
            'status'     => (string) ($row['status'] ?? Event::status($event)),
            'subject'    => $row['subject'] === null ? null : (string) $row['subject'],
            'from'       => $row['sender_used'] ?? $row['from_email'] ?? null,
            'to'         => self::emails($row['to_json'] ?? null),
            'cc'         => self::emails($row['cc_json'] ?? null),
            'tag'        => $row['tag'] ?? null,
            'template'   => $row['template'] ?? null,
            'transport'  => $row['transport_used'] ?? null,
            'source'     => (string) ($row['source'] ?? ''),
            'attempts'   => (int) ($row['attempts'] ?? 0),
            'created_at' => self::time($row['created_at'] ?? null),
            'sent_at'    => self::time($row['sent_at'] ?? null),
        ];
    }

    /**
     * Прежний плоский вид: письмо и событие на одном уровне, время числом.
     * Меняться он больше не будет — это застывший формат для старых подписок.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    public static function legacy(string $event, array $row, array $extra = []): array
    {
        return array_merge([
            'event'      => $event,
            'message_id' => (string) ($row['uuid'] ?? ''),
            'status'     => $event === 'sent' ? MessageRepository::SENT : MessageRepository::FAILED,
            'subject'    => $row['subject'] ?? null,
            'to'         => self::emails($row['to_json'] ?? null),
            'tag'        => $row['tag'] ?? null,
            'timestamp'  => time(),
        ], $extra);
    }

    /**
     * Событие второй версии в имени первой: старая подписка знает только sent и failed.
     */
    public static function legacyEvent(string $event): ?string
    {
        return match ($event) {
            Event::MESSAGE_SENT   => 'sent',
            Event::MESSAGE_FAILED => 'failed',
            default               => null,
        };
    }

    /**
     * Адреса из колонки со списком получателей.
     *
     * @return array<int, string>
     */
    private static function emails(mixed $json): array
    {
        $decoded = is_string($json) && $json !== '' ? json_decode($json, true) : [];

        return is_array($decoded) ? array_values(array_column($decoded, 'email')) : [];
    }

    /**
     * Время из базы в ISO 8601. Пустое так и остаётся пустым: письмо, которое ещё
     * не ушло, не должно получить выдуманную дату отправки.
     */
    private static function time(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return null;
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? $value : date('c', $timestamp);
    }
}
