<?php

declare(strict_types=1);

namespace Mailer\Repository;

use Mailer\Domain\Scope;
use Mailer\Message\Address;
use Mailer\Message\Attachment;
use Mailer\Message\Message;
use Mailer\Storage\Database;
use Mailer\Support\Str;

/**
 * Письма в базе: сохранение, поиск, статусы и статистика.
 */
final class MessageRepository
{
    public const QUEUED   = 'queued';
    public const SENDING  = 'sending';
    public const SENT     = 'sent';
    public const FAILED   = 'failed';
    public const CANCELED = 'canceled';

    public const STATUSES = [self::QUEUED, self::SENDING, self::SENT, self::FAILED, self::CANCELED];

    /** Слова короче этого полнотекстовый индекс MySQL не хранит (innodb_ft_min_token_size) */
    private const SEARCH_MIN_WORD = 3;

    /** Откуда пришло письмо */
    public const SOURCE_API      = 'api';
    public const SOURCE_SENDMAIL = 'sendmail';
    public const SOURCE_SMTPD    = 'smtpd';
    public const SOURCE_CLI      = 'cli';
    public const SOURCE_UI       = 'ui';

    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::instance();
    }

    /**
     * Сохраняет письмо в базу и возвращает его id.
     *
     * @param array<string, mixed> $options project_id, owner_id, transport_id, source, status,
     *                                      available_at, max_attempts, template, template_data,
     *                                      idempotency_key
     */
    public function store(Message $message, array $options = []): int
    {
        $uuid = (string) ($options['uuid'] ?? Str::uuid());

        return $this->db->insert('messages', [
            'uuid'             => $uuid,
            'project_id'       => $options['project_id'] ?? null,
            'owner_id'         => (int) ($options['owner_id'] ?? 0),
            'transport_id'     => $options['transport_id'] ?? null,
            'status'           => (string) ($options['status'] ?? self::QUEUED),
            'priority'         => (int) ($options['priority'] ?? $message->priority),
            'source'           => (string) ($options['source'] ?? self::SOURCE_API),
            'subject'          => $message->subject,
            'from_email'       => $message->from?->email,
            'from_name'        => $message->from?->name,
            'reply_to'         => $message->replyTo?->email,
            'to_json'          => $this->encodeAddresses($message->to),
            'cc_json'          => $this->encodeAddresses($message->cc),
            'bcc_json'         => $this->encodeAddresses($message->bcc),
            'headers_json'     => $message->headers === [] ? null : json_encode($message->headers, JSON_UNESCAPED_UNICODE),
            'text_body'        => $message->text !== '' ? $message->text : null,
            'html_body'        => $message->html !== '' ? $message->html : null,
            'attachments_json' => $this->encodeAttachments($message->attachments),
            'raw_mime'         => $message->raw,
            'envelope_from'    => $message->envelopeFrom,
            'envelope_to'      => $message->envelopeTo === [] ? null : json_encode($message->envelopeTo, JSON_UNESCAPED_UNICODE),
            'template'         => $options['template'] ?? null,
            'template_data'    => isset($options['template_data']) && $options['template_data'] !== []
                ? json_encode($options['template_data'], JSON_UNESCAPED_UNICODE)
                : null,
            'meta'             => $message->meta === [] ? null : json_encode($message->meta, JSON_UNESCAPED_UNICODE),
            'tag'              => $message->tag,
            'idempotency_key'  => $options['idempotency_key'] ?? null,
            'size'             => $message->approximateSize(),
            'attempts'         => 0,
            'max_attempts'     => (int) ($options['max_attempts'] ?? 5),
            'available_at'     => (string) ($options['available_at'] ?? Database::now()),
            'created_at'       => Database::now(),
            'updated_at'       => Database::now(),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id, ?Scope $scope = null): ?array
    {
        $condition = $scope === null ? '' : $scope->sql();

        return $this->db->selectOne(
            'SELECT * FROM messages WHERE id = :id' . ($condition === '' ? '' : ' AND ' . $condition),
            array_merge(['id' => $id], $scope === null ? [] : $scope->params())
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByUuid(string $uuid): ?array
    {
        return $this->db->selectOne('SELECT * FROM messages WHERE uuid = :uuid', ['uuid' => $uuid]);
    }

    /**
     * Ищет письмо по id или uuid — API принимает и то, и другое.
     *
     * @return array<string, mixed>|null
     */
    public function findAny(string $id): ?array
    {
        if (ctype_digit($id)) {
            $row = $this->find((int) $id);
            if ($row !== null) {
                return $row;
            }
        }

        return $this->findByUuid($id);
    }

    /**
     * Проверка повторной отправки: то же письмо с тем же ключом идемпотентности.
     *
     * @return array<string, mixed>|null
     */
    public function findByIdempotencyKey(int $projectId, string $key): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM messages WHERE project_id = :project AND idempotency_key = :key ORDER BY id DESC',
            ['project' => $projectId, 'key' => $key]
        );
    }

    /**
     * Восстанавливает объект письма из строки базы — с ним работает транспорт.
     *
     * @param array<string, mixed> $row
     */
    public function toMessage(array $row): Message
    {
        $message = new Message();

        if (($row['from_email'] ?? null) !== null && $row['from_email'] !== '') {
            $message->from = new Address((string) $row['from_email'], (string) ($row['from_name'] ?? ''));
        }

        $message->to  = $this->decodeAddresses($row['to_json'] ?? null);
        $message->cc  = $this->decodeAddresses($row['cc_json'] ?? null);
        $message->bcc = $this->decodeAddresses($row['bcc_json'] ?? null);

        if (($row['reply_to'] ?? null) !== null && $row['reply_to'] !== '') {
            $message->replyTo = new Address((string) $row['reply_to']);
        }

        $message->subject = (string) ($row['subject'] ?? '');
        $message->text    = (string) ($row['text_body'] ?? '');
        $message->html    = (string) ($row['html_body'] ?? '');
        $message->raw     = $row['raw_mime'] !== null && $row['raw_mime'] !== '' ? (string) $row['raw_mime'] : null;
        $message->headers = $this->decodeArray($row['headers_json'] ?? null);
        $message->meta    = $this->decodeArray($row['meta'] ?? null);
        $message->tag     = $row['tag'] !== null ? (string) $row['tag'] : null;
        $message->priority = (int) ($row['priority'] ?? 100);

        $message->envelopeFrom = $row['envelope_from'] !== null ? (string) $row['envelope_from'] : null;
        $message->envelopeTo   = array_values($this->decodeArray($row['envelope_to'] ?? null));

        foreach ($this->decodeArray($row['attachments_json'] ?? null) as $item) {
            if (is_array($item)) {
                $message->attachments[] = Attachment::fromStored($item);
            }
        }

        return $message;
    }

    /**
     * Список писем с фильтрами и постраничностью.
     *
     * @param array<string, mixed> $filters status, project_id, transport_id, source, search, tag, date_from, date_to
     * @return array{items: array<int, array<string, mixed>>, total: int, page: int, pages: int, per_page: int}
     */
    public function paginate(array $filters = [], int $page = 1, int $perPage = 30, ?Scope $scope = null): array
    {
        $result = $this->search($filters, $page, $perPage, $this->hasFulltextIndex(), $scope);

        // Полнотекстовый индекс ищет слова целиком и их начала, поэтому «mail» не найдёт
        // «gmail.com». Если по словам нашлось меньше страницы, повторяем перебором —
        // пользователь получает полный ответ, а быстрый путь остаётся для широких запросов.
        if (!empty($filters['search']) && $result['total'] < $perPage && $this->hasFulltextIndex()) {
            return $this->search($filters, $page, $perPage, false, $scope);
        }

        return $result;
    }

    /**
     * Выборка страницы с указанным способом поиска.
     *
     * @param array<string, mixed> $filters
     * @return array{items: array<int, array<string, mixed>>, total: int, page: int, pages: int, per_page: int}
     */
    private function search(array $filters, int $page, int $perPage, bool $fulltext, ?Scope $scope = null): array
    {
        [$where, $params] = $this->buildWhere($filters, $fulltext, $scope);

        $total = (int) $this->db->value('SELECT COUNT(*) FROM messages ' . $where, $params);

        $perPage = max(1, min(200, $perPage));
        $pages   = max(1, (int) ceil($total / $perPage));
        $page    = max(1, min($page, $pages));
        $offset  = ($page - 1) * $perPage;

        $items = $this->db->select(
            'SELECT id, uuid, project_id, transport_id, transport_used, status, source, subject, from_email,
                    sender_used, to_json, tag, attempts, max_attempts, size, available_at, last_error,
                    sent_at, created_at, updated_at
             FROM messages ' . $where . ' ORDER BY id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset,
            $params
        );

        return [
            'items'    => $items,
            'total'    => $total,
            'page'     => $page,
            'pages'    => $pages,
            'per_page' => $perPage,
        ];
    }

    /**
     * Есть ли в базе полнотекстовый индекс. Проверяем один раз на процесс: код может
     * приехать на сервер раньше, чем накатят миграцию, и тогда MATCH просто не сработает —
     * поиск в этом случае должен остаться на LIKE, а не падать с ошибкой.
     */
    private function hasFulltextIndex(): bool
    {
        static $available = null;

        if ($this->db->isSqlite()) {
            return false;
        }

        if ($available === null) {
            $available = (int) $this->db->value(
                'SELECT COUNT(*) FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND INDEX_NAME = :index',
                ['table' => 'messages', 'index' => 'ft_messages_search']
            ) > 0;
        }

        return $available;
    }

    /**
     * Условие поиска по письмам.
     *
     * В MySQL ищем полнотекстовым индексом по теме, получателям и отправителю: перебор
     * с LIKE на десятках тысяч писем стоит сотни миллисекунд, а MATCH идёт по индексу.
     * Короткие запросы (короче ft_min_token_size) и поиск по идентификатору полнотекстовый
     * индекс не берёт — для них остаётся LIKE. В SQLite полнотекстового индекса нет,
     * поэтому там всегда LIKE.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    public static function searchCondition(string $search, bool $fulltext): array
    {
        $search = trim($search);
        $words  = preg_split('/\s+/u', $search) ?: [];
        $short  = false;

        foreach ($words as $word) {
            if (mb_strlen(trim($word, '*+-<>~()"')) < self::SEARCH_MIN_WORD) {
                $short = true;
            }
        }

        // Идентификатор письма ищем как есть: полнотекстовый индекс рвёт его на куски
        $looksLikeUuid = preg_match('/^[0-9a-f-]{8,}$/i', $search) === 1;

        if (!$fulltext || $short || $looksLikeUuid || $words === []) {
            // Имя параметра в MySQL нельзя повторять в одном запросе — заводим четыре
            $needle = '%' . $search . '%';

            return [
                '(subject LIKE :search_subject OR to_json LIKE :search_to'
                    . ' OR from_email LIKE :search_from OR uuid LIKE :search_uuid)',
                [
                    'search_subject' => $needle,
                    'search_to'      => $needle,
                    'search_from'    => $needle,
                    'search_uuid'    => $needle,
                ],
            ];
        }

        // Каждое слово обязательно, последнее ищем ещё и по началу: «зака» найдёт «заказ»
        $terms = [];
        foreach ($words as $index => $word) {
            $word = preg_replace('/[+\-<>~()*"@]+/u', ' ', $word);
            $word = trim((string) $word);

            if ($word === '') {
                continue;
            }

            $terms[] = '+' . $word . ($index === count($words) - 1 ? '*' : '');
        }

        if ($terms === []) {
            $needle = '%' . $search . '%';

            return [
                '(subject LIKE :search_subject OR to_json LIKE :search_to'
                    . ' OR from_email LIKE :search_from OR uuid LIKE :search_uuid)',
                [
                    'search_subject' => $needle,
                    'search_to'      => $needle,
                    'search_from'    => $needle,
                    'search_uuid'    => $needle,
                ],
            ];
        }

        return [
            'MATCH (subject, to_json, from_email) AGAINST (:search_match IN BOOLEAN MODE)',
            ['search_match' => implode(' ', $terms)],
        ];
    }

    /**
     * Условие WHERE по фильтрам.
     *
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildWhere(array $filters, bool $fulltext = false, ?Scope $scope = null): array
    {
        $conditions = [];
        $params     = [];

        if ($scope !== null && $scope->sql() !== '') {
            $conditions[] = $scope->sql();
            $params       = $scope->params();
        }

        if (!empty($filters['status'])) {
            $conditions[]     = 'status = :status';
            $params['status'] = (string) $filters['status'];
        }

        if (!empty($filters['project_id'])) {
            $conditions[]         = 'project_id = :project_id';
            $params['project_id'] = (int) $filters['project_id'];
        }

        if (!empty($filters['transport_id'])) {
            $conditions[]           = 'transport_id = :transport_id';
            $params['transport_id'] = (int) $filters['transport_id'];
        }

        if (!empty($filters['source'])) {
            $conditions[]     = 'source = :source';
            $params['source'] = (string) $filters['source'];
        }

        if (!empty($filters['tag'])) {
            $conditions[]  = 'tag = :tag';
            $params['tag'] = (string) $filters['tag'];
        }

        if (!empty($filters['search'])) {
            [$condition, $searchParams] = self::searchCondition((string) $filters['search'], $fulltext);

            $conditions[] = $condition;
            $params       = array_merge($params, $searchParams);
        }

        if (!empty($filters['date_from'])) {
            $conditions[]        = 'created_at >= :date_from';
            $params['date_from'] = (string) $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $conditions[]      = 'created_at <= :date_to';
            $params['date_to'] = (string) $filters['date_to'];
        }

        return [$conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions), $params];
    }

    /**
     * Обновление произвольных полей письма.
     *
     * @param array<string, mixed> $fields
     */
    public function update(int $id, array $fields): void
    {
        $fields['updated_at'] = Database::now();

        $this->db->update('messages', $fields, ['id' => $id]);
    }

    /**
     * Сколько писем в каком статусе.
     *
     * @return array<string, int>
     */
    public function countByStatus(?Scope $scope = null): array
    {
        $result = array_fill_keys(self::STATUSES, 0);

        $condition = $scope === null ? '' : $scope->sql();
        $params    = $scope === null ? [] : $scope->params();

        $rows = $this->db->select(
            'SELECT status, COUNT(*) AS total FROM messages'
            . ($condition === '' ? '' : ' WHERE ' . $condition) . ' GROUP BY status',
            $params
        );

        foreach ($rows as $row) {
            $result[(string) $row['status']] = (int) $row['total'];
        }

        return $result;
    }

    /**
     * Сводка для дашборда. С областью видимости считаются только письма владельца —
     * обзор у каждого свой.
     *
     * @return array<string, mixed>
     */
    public function stats(?Scope $scope = null): array
    {
        $today = date('Y-m-d');

        $condition = $scope === null ? '' : $scope->sql();
        $own       = $condition === '' ? '' : ' AND ' . $condition;
        $params    = $scope === null ? [] : $scope->params();

        return [
            'by_status'    => $this->countByStatus($scope),
            'total'        => (int) $this->db->value(
                'SELECT COUNT(*) FROM messages' . ($condition === '' ? '' : ' WHERE ' . $condition),
                $params
            ),
            'today_sent'   => (int) $this->db->value(
                "SELECT COUNT(*) FROM messages WHERE status = 'sent' AND sent_at >= :from" . $own,
                array_merge(['from' => $today . ' 00:00:00'], $params)
            ),
            'today_failed' => (int) $this->db->value(
                "SELECT COUNT(*) FROM messages WHERE status = 'failed' AND updated_at >= :from" . $own,
                array_merge(['from' => $today . ' 00:00:00'], $params)
            ),
            'queue_ready'  => (int) $this->db->value(
                "SELECT COUNT(*) FROM messages WHERE status = 'queued' AND (available_at IS NULL OR available_at <= :now)" . $own,
                array_merge(['now' => Database::now()], $params)
            ),
            'queue_delayed' => (int) $this->db->value(
                "SELECT COUNT(*) FROM messages WHERE status = 'queued' AND available_at > :now" . $own,
                array_merge(['now' => Database::now()], $params)
            ),
            'oldest_queued' => $this->db->value(
                "SELECT MIN(created_at) FROM messages WHERE status = 'queued'" . $own,
                $params
            ),
        ];
    }

    /**
     * Статистика по дням за последние N дней — для графика в панели.
     *
     * @return array<int, array{date: string, sent: int, failed: int, total: int}>
     */
    public function dailyStats(int $days = 14, ?Scope $scope = null): array
    {
        $from = date('Y-m-d', strtotime('-' . max(1, $days - 1) . ' days'));

        $condition = $scope === null ? '' : $scope->sql();

        $rows = $this->db->select(
            "SELECT substr(created_at, 1, 10) AS day,
                    COUNT(*) AS total,
                    SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) AS sent,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed
             FROM messages WHERE created_at >= :from" . ($condition === '' ? '' : ' AND ' . $condition)
             . ' GROUP BY day ORDER BY day',
            array_merge(['from' => $from . ' 00:00:00'], $scope === null ? [] : $scope->params())
        );

        $byDay = [];
        foreach ($rows as $row) {
            $byDay[(string) $row['day']] = [
                'sent'   => (int) $row['sent'],
                'failed' => (int) $row['failed'],
                'total'  => (int) $row['total'],
            ];
        }

        $result = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day      = date('Y-m-d', strtotime('-' . $i . ' days'));
            $result[] = [
                'date'   => $day,
                'sent'   => $byDay[$day]['sent'] ?? 0,
                'failed' => $byDay[$day]['failed'] ?? 0,
                'total'  => $byDay[$day]['total'] ?? 0,
            ];
        }

        return $result;
    }

    /**
     * Удаляет письмо вместе с его событиями и вложениями.
     */
    public function delete(int $id): void
    {
        $row = $this->find($id);
        if ($row === null) {
            return;
        }

        // Сначала база одной транзакцией, и только потом файлы: если удаление сорвётся,
        // письмо останется целым, а не превратится в запись без вложений
        $this->db->transaction(function () use ($id): void {
            (new EventRepository($this->db))->deleteForMessage($id);
            $this->db->delete('messages', ['id' => $id]);
        });

        foreach ($this->decodeArray($row['attachments_json'] ?? null) as $attachment) {
            $path = is_array($attachment) ? (string) ($attachment['path'] ?? '') : '';
            if ($path !== '' && is_file($path)) {
                @unlink($path);
            }
        }
    }

    /**
     * Чистка старых писем. Возвращает, сколько удалили.
     */
    public function purge(string $status, int $olderThanDays): int
    {
        $border = date('Y-m-d H:i:s', strtotime('-' . max(0, $olderThanDays) . ' days'));

        $rows = $this->db->select(
            'SELECT id FROM messages WHERE status = :status AND updated_at < :border',
            ['status' => $status, 'border' => $border]
        );

        foreach ($rows as $row) {
            $this->delete((int) $row['id']);
        }

        return count($rows);
    }

    // --- Преобразования полей ------------------------------------------------

    /**
     * @param array<int, Address> $addresses
     */
    private function encodeAddresses(array $addresses): ?string
    {
        if ($addresses === []) {
            return null;
        }

        return (string) json_encode(
            array_map(static fn (Address $a): array => $a->toArray(), $addresses),
            JSON_UNESCAPED_UNICODE
        );
    }

    /**
     * @return array<int, Address>
     */
    private function decodeAddresses(mixed $json): array
    {
        $result = [];

        foreach ($this->decodeArray($json) as $item) {
            if (!is_array($item) || ($item['email'] ?? '') === '') {
                continue;
            }

            try {
                $result[] = new Address((string) $item['email'], (string) ($item['name'] ?? ''));
            } catch (\Throwable) {
                continue;
            }
        }

        return $result;
    }

    /**
     * @param array<int, Attachment> $attachments
     */
    private function encodeAttachments(array $attachments): ?string
    {
        if ($attachments === []) {
            return null;
        }

        return (string) json_encode(
            array_map(static fn (Attachment $a): array => $a->toArray(), $attachments),
            JSON_UNESCAPED_UNICODE
        );
    }

    /**
     * @return array<int|string, mixed>
     */
    public function decodeArray(mixed $json): array
    {
        if (!is_string($json) || $json === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }
}
