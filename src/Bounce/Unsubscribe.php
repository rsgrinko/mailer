<?php

declare(strict_types=1);

namespace Mailer\Bounce;

use Mailer\Support\Config;

/**
 * Отписка одной кнопкой: заголовки `List-Unsubscribe` и ссылка с подписанным токеном.
 *
 * Gmail и Mail.ru требуют такую кнопку от массовых рассылок, иначе письма уходят в спам.
 * Токен подписан ключом сервиса (`APP_KEY`), поэтому базу под него заводить не нужно:
 * в самой ссылке лежит адрес и проект, а подпись не даёт подставить чужой адрес.
 */
final class Unsubscribe
{
    /** Сколько живёт ссылка отписки — год: письмо могут открыть и через полгода */
    private const LIFETIME = 31536000;

    /**
     * Ставить ли заголовки отписки письмам этого проекта.
     *
     * @param array<string, mixed>|null $project
     */
    public static function enabled(?array $project): bool
    {
        if (!(bool) Config::get('unsubscribe.enabled', false) || self::baseUrl() === '') {
            return false;
        }

        // Без проекта (письмо из панели или консоли) отписывать некого и незачем
        return $project !== null && (int) ($project['unsubscribe'] ?? 0) === 1;
    }

    /**
     * Заголовки для письма. Пустой массив — отписка этому письму не положена.
     *
     * @return array<string, string>
     */
    public static function headers(string $email, int $projectId): array
    {
        $url = self::url($email, $projectId);

        if ($url === '') {
            return [];
        }

        return [
            'List-Unsubscribe'      => '<' . $url . '>',
            // Именно это разрешает почтовику отписать нажатием, без открытия страницы
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ];
    }

    /**
     * Ссылка отписки для адреса.
     */
    public static function url(string $email, int $projectId): string
    {
        $base = self::baseUrl();

        if ($base === '' || $email === '') {
            return '';
        }

        return $base . '/unsubscribe/' . self::token($email, $projectId);
    }

    /**
     * Подписанный токен: данные и подпись через точку.
     */
    public static function token(string $email, int $projectId): string
    {
        $payload = self::encode((string) json_encode([
            'e' => mb_strtolower(trim($email)),
            'p' => $projectId,
            't' => time(),
        ], JSON_UNESCAPED_UNICODE));

        return $payload . '.' . self::encode(self::signature($payload));
    }

    /**
     * Разбирает токен. Null — подпись не сошлась, токен испорчен или просрочен.
     *
     * @return array{email: string, project_id: int}|null
     */
    public static function parse(string $token): ?array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 2) {
            return null;
        }

        [$payload, $signature] = $parts;

        if (!hash_equals(self::signature($payload), self::decode($signature))) {
            return null;
        }

        $data = json_decode(self::decode($payload), true);

        if (!is_array($data) || !isset($data['e'])) {
            return null;
        }

        if (time() - (int) ($data['t'] ?? 0) > self::LIFETIME) {
            return null;
        }

        return [
            'email'      => (string) $data['e'],
            'project_id' => (int) ($data['p'] ?? 0),
        ];
    }

    /**
     * Подпись ключом сервиса. Без APP_KEY подписывать нечем — тогда и ссылок не будет.
     */
    private static function signature(string $payload): string
    {
        return hash_hmac('sha256', $payload, (string) Config::get('app.key', ''), true);
    }

    private static function baseUrl(): string
    {
        $url = trim((string) Config::get('app.url', ''));

        return (string) Config::get('app.key', '') === '' ? '' : rtrim($url, '/');
    }

    /**
     * base64 для адресной строки: без плюсов, слэшей и хвостовых знаков равенства.
     */
    private static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function decode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? '' : $decoded;
    }
}
