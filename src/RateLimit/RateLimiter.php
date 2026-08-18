<?php

declare(strict_types=1);

namespace Mailer\RateLimit;

use Mailer\Storage\Database;

/**
 * Лимиты отправки. Считаем письма по проектам (в час и в сутки) и по транспортам (в сутки) —
 * у того же Яндекса есть суточное ограничение, и упираться в него молча не хочется.
 *
 * Счётчики лежат в таблице counters: ключ + значение + время сброса.
 */
final class RateLimiter
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::instance();
    }

    /**
     * Проверяет лимиты проекта. Возвращает текст ошибки или null, если всё в порядке.
     *
     * @param array<string, mixed> $project
     */
    public function checkProject(array $project): ?string
    {
        $id = (int) ($project['id'] ?? 0);

        $hourLimit = (int) ($project['rate_limit_hour'] ?? 0);
        if ($hourLimit > 0 && $this->value($this->hourKey('project', $id)) >= $hourLimit) {
            return 'Превышен часовой лимит проекта: ' . $hourLimit . ' писем в час';
        }

        $dayLimit = (int) ($project['rate_limit_day'] ?? 0);
        if ($dayLimit > 0 && $this->value($this->dayKey('project', $id)) >= $dayLimit) {
            return 'Превышен суточный лимит проекта: ' . $dayLimit . ' писем в сутки';
        }

        return null;
    }

    /**
     * Проверяет суточный лимит транспорта.
     *
     * @param array<string, mixed> $transport
     */
    public function checkTransport(array $transport): ?string
    {
        $limit = (int) ($transport['daily_limit'] ?? 0);
        if ($limit <= 0) {
            return null;
        }

        $id = (int) ($transport['id'] ?? 0);
        if ($this->value($this->dayKey('transport', $id)) >= $limit) {
            return 'Превышен суточный лимит транспорта «' . ($transport['name'] ?? '') . '»: ' . $limit . ' писем в сутки';
        }

        return null;
    }

    /**
     * Отмечаем принятое письмо проекта.
     */
    public function hitProject(int $projectId): void
    {
        $this->increment($this->hourKey('project', $projectId), strtotime('+1 hour'));
        $this->increment($this->dayKey('project', $projectId), strtotime('tomorrow'));
    }

    /**
     * Отмечаем отправленное через транспорт письмо.
     */
    public function hitTransport(int $transportId): void
    {
        $this->increment($this->dayKey('transport', $transportId), strtotime('tomorrow'));
    }

    /**
     * Сколько уже использовано — показываем в панели.
     *
     * @return array{hour: int, day: int}
     */
    public function projectUsage(int $projectId): array
    {
        return [
            'hour' => $this->value($this->hourKey('project', $projectId)),
            'day'  => $this->value($this->dayKey('project', $projectId)),
        ];
    }

    public function transportUsage(int $transportId): int
    {
        return $this->value($this->dayKey('transport', $transportId));
    }

    /**
     * Убирает счётчики, время которых прошло.
     */
    public function cleanup(): int
    {
        return $this->db->execute(
            'DELETE FROM counters WHERE expires_at IS NOT NULL AND expires_at < :now',
            ['now' => Database::now()]
        );
    }

    /**
     * Сбросить конкретный счётчик (кнопка в панели).
     */
    public function reset(string $key): void
    {
        $this->db->delete('counters', ['counter_key' => $key]);
    }

    private function hourKey(string $scope, int $id): string
    {
        return $scope . ':' . $id . ':hour:' . date('Y-m-d-H');
    }

    private function dayKey(string $scope, int $id): string
    {
        return $scope . ':' . $id . ':day:' . date('Y-m-d');
    }

    private function value(string $key): int
    {
        $row = $this->db->selectOne('SELECT value FROM counters WHERE counter_key = :key', ['key' => $key]);

        return $row === null ? 0 : (int) $row['value'];
    }

    /**
     * Увеличивает счётчик на единицу. Пишем одним запросом, чтобы не поймать гонку
     * между воркером и веб-частью.
     */
    private function increment(string $key, int $expiresAt): void
    {
        // Имена параметров не повторяем: MySQL с настоящими подготовленными
        // выражениями не разрешает использовать один параметр дважды
        $params = [
            'key'      => $key,
            'expires'  => date('Y-m-d H:i:s', $expiresAt),
            'now'      => Database::now(),
            'now_upd'  => Database::now(),
        ];

        $sql = $this->db->isSqlite()
            ? 'INSERT INTO counters (counter_key, value, expires_at, updated_at) VALUES (:key, 1, :expires, :now)
               ON CONFLICT (counter_key) DO UPDATE SET value = value + 1, updated_at = :now_upd'
            : 'INSERT INTO counters (counter_key, value, expires_at, updated_at) VALUES (:key, 1, :expires, :now)
               ON DUPLICATE KEY UPDATE value = value + 1, updated_at = :now_upd';

        $this->db->execute($sql, $params);
    }
}
