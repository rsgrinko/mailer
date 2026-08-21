<?php

declare(strict_types=1);

namespace Mailer\Repository;

use Mailer\Domain\Scope;
use Mailer\Storage\Database;

/**
 * История событий по письму: приняли, попытались отправить, ошиблись, отправили.
 */
final class EventRepository
{
    public const ACCEPTED  = 'accepted';
    public const ATTEMPT   = 'attempt';
    public const SENT      = 'sent';
    public const FAILED    = 'failed';
    public const RETRY     = 'retry';
    public const CANCELED  = 'canceled';
    public const REQUEUED  = 'requeued';
    public const WEBHOOK   = 'webhook';
    public const SENDER    = 'sender';
    public const SUPPRESSED = 'suppressed';

    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::instance();
    }

    /**
     * @param array<string, mixed> $meta
     */
    public function add(int $messageId, string $type, string $message = '', array $meta = []): void
    {
        $this->db->insert('message_events', [
            'message_id' => $messageId,
            'type'       => $type,
            'message'    => $message,
            'meta'       => $meta === [] ? null : json_encode($meta, JSON_UNESCAPED_UNICODE),
            'created_at' => Database::now(),
        ]);
    }

    /**
     * Все события письма по порядку.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forMessage(int $messageId): array
    {
        $rows = $this->db->select(
            'SELECT * FROM message_events WHERE message_id = :id ORDER BY id',
            ['id' => $messageId]
        );

        foreach ($rows as $index => $row) {
            $rows[$index]['meta'] = $row['meta'] === null || $row['meta'] === ''
                ? []
                : (array) json_decode((string) $row['meta'], true);
        }

        return $rows;
    }

    /**
     * Кого у этих писем вычеркнул стоп-лист. Закрытые адреса убираются из письма
     * ещё на приёме — иначе воркер бы им написал, — и в самом письме их нет.
     * Список и карточка берут их отсюда, потому что письму со статусом
     * suppressed иначе нечего показать в графе «Кому».
     *
     * Одним запросом на страницу списка, а не по запросу на строку.
     *
     * @param array<int, int> $messageIds
     * @return array<int, array<string, string>> id письма => [адрес => причина]
     */
    public function suppressedRecipients(array $messageIds): array
    {
        $messageIds = array_values(array_unique(array_map('intval', $messageIds)));

        if ($messageIds === []) {
            return [];
        }

        // Своё имя каждому идентификатору: повтор имени MySQL не принимает
        $names  = [];
        $params = ['type' => self::SUPPRESSED];
        foreach ($messageIds as $index => $messageId) {
            $name          = 'sup_' . $index;
            $names[]       = ':' . $name;
            $params[$name] = $messageId;
        }

        $rows = $this->db->select(
            'SELECT message_id, meta FROM message_events'
            . ' WHERE type = :type AND message_id IN (' . implode(', ', $names) . ')'
            . ' ORDER BY id',
            $params
        );

        $result = [];
        foreach ($rows as $row) {
            $meta = $row['meta'] === null || $row['meta'] === ''
                ? []
                : (array) json_decode((string) $row['meta'], true);

            foreach ((array) ($meta['recipients'] ?? []) as $email => $reason) {
                $result[(int) $row['message_id']][(string) $email] = (string) $reason;
            }
        }

        return $result;
    }

    /**
     * Последние события по всем письмам — лента на дашборде.
     *
     * @return array<int, array<string, mixed>>
     */
    public function latest(int $limit = 50, ?Scope $scope = null): array
    {
        $limit = max(1, $limit);

        // Своей области видимости нет — видно всё, включая события удалённых писем
        if ($scope === null || $scope->isAll()) {
            return $this->db->select(
                'SELECT e.*, m.uuid, m.subject FROM message_events e
                 LEFT JOIN messages m ON m.id = e.message_id
                 ORDER BY e.id DESC LIMIT ' . $limit
            );
        }

        // Событие принадлежит письму, поэтому область видимости берём у письма.
        // Здесь именно INNER JOIN: событие без письма ничьё, а главное — LEFT JOIN
        // заставляет базу начинать с таблицы событий и сортировать её целиком.
        // С INNER она вольна пойти от писем владельца по индексу idx_messages_owner,
        // и это заметно дешевле (замеры — в docs/LOADTEST.md).
        return $this->db->select(
            'SELECT e.*, m.uuid, m.subject FROM message_events e
             JOIN messages m ON m.id = e.message_id
             WHERE ' . $scope->sql('m.owner_id') . '
             ORDER BY e.id DESC LIMIT ' . $limit,
            $scope->params()
        );
    }

    /**
     * Чистка старых событий.
     */
    public function deleteForMessage(int $messageId): void
    {
        $this->db->delete('message_events', ['message_id' => $messageId]);
    }
}
