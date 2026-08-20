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
     * Последние события по всем письмам — лента на дашборде.
     *
     * @return array<int, array<string, mixed>>
     */
    public function latest(int $limit = 50, ?Scope $scope = null): array
    {
        // Событие принадлежит письму, поэтому и область видимости берём у письма
        $condition = $scope === null ? '' : $scope->sql('m.owner_id');

        return $this->db->select(
            'SELECT e.*, m.uuid, m.subject FROM message_events e
             LEFT JOIN messages m ON m.id = e.message_id'
            . ($condition === '' ? '' : ' WHERE ' . $condition)
            . ' ORDER BY e.id DESC LIMIT ' . max(1, $limit),
            $scope === null ? [] : $scope->params()
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
