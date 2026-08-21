<?php

declare(strict_types=1);

namespace Mailer\Repository;

use Mailer\Storage\Database;

/**
 * Токены «запомнить меня»: по ним панель узнаёт вернувшегося пользователя,
 * когда сессия уже кончилась.
 *
 * В куке лежит пара «selector:validator». Selector — это адрес записи, по нему идёт
 * поиск; validator в базе хранится только хешем и сверяется через hash_equals.
 * Поэтому утёкшая база сама по себе не пускает в панель, а подобрать validator
 * перебором нельзя — совпадение проверяется по одной конкретной записи.
 *
 * Использованный токен заменяется новым (ротация): украденная кука перестаёт
 * работать, как только настоящий владелец зайдёт снова.
 */
final class RememberTokenRepository
{
    /** Длина половинок токена в байтах — в куке они шестнадцатеричные, то есть вдвое длиннее */
    private const SELECTOR_BYTES  = 8;
    private const VALIDATOR_BYTES = 32;

    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::instance();
    }

    /**
     * Заводит токен и возвращает значение для куки.
     */
    public function issue(int $userId, int $days, string $ip = ''): string
    {
        $selector  = bin2hex(random_bytes(self::SELECTOR_BYTES));
        $validator = bin2hex(random_bytes(self::VALIDATOR_BYTES));

        $this->db->insert('remember_tokens', [
            'user_id'    => $userId,
            'selector'   => $selector,
            'token_hash' => self::hash($validator),
            'ip'         => $ip === '' ? null : mb_substr($ip, 0, 45),
            'expires_at' => date('Y-m-d H:i:s', time() + max(1, $days) * 86400),
            'created_at' => Database::now(),
        ]);

        return $selector . ':' . $validator;
    }

    /**
     * Ищет живой токен по значению куки. Просроченный удаляется сразу, чужой
     * validator ответа не меняет — наружу в обоих случаях уходит null.
     *
     * @return array<string, mixed>|null
     */
    public function match(string $cookie): ?array
    {
        [$selector, $validator] = self::split($cookie);

        if ($selector === '' || $validator === '') {
            return null;
        }

        $row = $this->db->selectOne(
            'SELECT * FROM remember_tokens WHERE selector = :selector',
            ['selector' => $selector]
        );

        if ($row === null) {
            return null;
        }

        if (strtotime((string) $row['expires_at']) < time()) {
            $this->delete($selector);

            return null;
        }

        if (!hash_equals((string) $row['token_hash'], self::hash($validator))) {
            // Селектор верный, а validator нет — куку либо подделали, либо она устарела
            // после ротации. Оставлять такую запись живой незачем
            $this->delete($selector);

            return null;
        }

        return $row;
    }

    /**
     * Меняет validator у токена и возвращает новую куку: старая перестаёт работать.
     *
     * Selector остаётся прежним намеренно. Придут со старой кукой — запись найдётся,
     * а validator не сойдётся, и токен погаснет весь: это ровно тот случай, когда куку
     * украли и ей воспользовались двое. Смени мы и selector, старая кука просто
     * никуда бы не вела, и о краже никто бы не узнал.
     */
    public function rotate(int $id, string $ip = ''): string
    {
        $row = $this->db->selectOne('SELECT selector FROM remember_tokens WHERE id = :id', ['id' => $id]);

        if ($row === null) {
            return '';
        }

        $validator = bin2hex(random_bytes(self::VALIDATOR_BYTES));

        $this->db->update('remember_tokens', [
            'token_hash'   => self::hash($validator),
            'ip'           => $ip === '' ? null : mb_substr($ip, 0, 45),
            'last_used_at' => Database::now(),
        ], ['id' => $id]);

        return (string) $row['selector'] . ':' . $validator;
    }

    public function delete(string $selector): void
    {
        $this->db->delete('remember_tokens', ['selector' => $selector]);
    }

    /**
     * Гасит все токены пользователя: смена пароля, отключение, выход «везде».
     */
    public function forgetUser(int $userId): int
    {
        return $this->db->delete('remember_tokens', ['user_id' => $userId]);
    }

    /**
     * Сколько токенов сейчас у пользователя — панели и тестам.
     */
    public function countForUser(int $userId): int
    {
        return (int) $this->db->value(
            'SELECT COUNT(*) FROM remember_tokens WHERE user_id = :user_id',
            ['user_id' => $userId]
        );
    }

    /**
     * Убирает просроченное — зовётся воркером.
     */
    public function purgeExpired(): int
    {
        return $this->db->execute(
            'DELETE FROM remember_tokens WHERE expires_at < :now',
            ['now' => Database::now()]
        );
    }

    /**
     * Разбирает куку на половинки.
     *
     * @return array{0: string, 1: string}
     */
    private static function split(string $cookie): array
    {
        $parts = explode(':', $cookie, 2);

        $selector  = trim($parts[0] ?? '');
        $validator = trim($parts[1] ?? '');

        // В куке только шестнадцатеричные символы: всё остальное — не наш токен
        if (!ctype_xdigit($selector) || !ctype_xdigit($validator)) {
            return ['', ''];
        }

        return [$selector, $validator];
    }

    private static function hash(string $validator): string
    {
        return hash('sha256', $validator);
    }
}
