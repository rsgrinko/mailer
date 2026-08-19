<?php

declare(strict_types=1);

namespace Mailer\RateLimit;

use Mailer\Domain\Project;
use Mailer\Domain\TransportProfile;
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
    public function checkProject(array $row): ?string
    {
        $project = Project::fromRow($row);

        if ($project->rateLimitHour > 0 && $this->count($this->hourKey('project', $project->id)) >= $project->rateLimitHour) {
            return 'Превышен часовой лимит проекта: ' . $project->rateLimitHour . ' писем в час';
        }

        if ($project->rateLimitDay > 0 && $this->count($this->dayKey('project', $project->id)) >= $project->rateLimitDay) {
            return 'Превышен суточный лимит проекта: ' . $project->rateLimitDay . ' писем в сутки';
        }

        return null;
    }

    /**
     * Проверяет суточный лимит транспорта.
     *
     * @param array<string, mixed> $transport
     */
    public function checkTransport(array $row): ?string
    {
        $transport = TransportProfile::fromRow($row);

        if ($transport->dailyLimit <= 0) {
            return null;
        }

        if ($this->count($this->dayKey('transport', $transport->id)) >= $transport->dailyLimit) {
            return 'Превышен суточный лимит транспорта «' . $transport->name . '»: ' . $transport->dailyLimit . ' писем в сутки';
        }

        return null;
    }

    /**
     * Отмечаем принятое письмо проекта.
     */
    public function hitProject(int $projectId): void
    {
        $this->hit($this->hourKey('project', $projectId), strtotime('+1 hour'));
        $this->hit($this->dayKey('project', $projectId), strtotime('tomorrow'));
    }

    /**
     * Отмечаем отправленное через транспорт письмо.
     */
    public function hitTransport(int $transportId): void
    {
        $this->hit($this->dayKey('transport', $transportId), strtotime('tomorrow'));
    }

    /**
     * Сколько уже использовано — показываем в панели.
     *
     * @return array{hour: int, day: int}
     */
    public function projectUsage(int $projectId): array
    {
        return [
            'hour' => $this->count($this->hourKey('project', $projectId)),
            'day'  => $this->count($this->dayKey('project', $projectId)),
        ];
    }

    public function transportUsage(int $transportId): int
    {
        return $this->count($this->dayKey('transport', $transportId));
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
     * Все счётчики — панель показывает их на странице состояния.
     *
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        return $this->db->select('SELECT * FROM counters ORDER BY counter_key');
    }

    /**
     * Снести все счётчики разом (кнопка «сбросить лимиты»).
     */
    public function resetAll(): int
    {
        return $this->db->execute('DELETE FROM counters');
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

    /**
     * Текущее значение счётчика.
     */
    public function count(string $key): int
    {
        $row = $this->db->selectOne('SELECT value FROM counters WHERE counter_key = :key', ['key' => $key]);

        return $row === null ? 0 : (int) $row['value'];
    }

    /**
     * Увеличивает счётчик на единицу. Пишем одним запросом, чтобы не поймать гонку
     * между воркером и веб-частью.
     */
    public function hit(string $key, int $expiresAt): void
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
