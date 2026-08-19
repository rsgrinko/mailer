<?php

declare(strict_types=1);

namespace Mailer\Security;

use Mailer\Support\MailerException;
use Mailer\Support\Str;

/**
 * Пароли пользователей панели. Храним только хеш (bcrypt/argon — что выберет PHP).
 */
final class Password
{
    /** Единственное требование к паролю */
    public const MIN_LENGTH = 6;

    /**
     * Хеширует пароль, предварительно проверив его длину.
     */
    public static function hash(string $password): string
    {
        $error = self::check($password);
        if ($error !== null) {
            throw new MailerException($error);
        }

        return password_hash($password, PASSWORD_DEFAULT);
    }

    /**
     * Подходит ли пароль к хешу.
     */
    public static function verify(string $password, string $hash): bool
    {
        return $hash !== '' && password_verify($password, $hash);
    }

    /**
     * Проверка пароля перед сохранением. Возвращает текст ошибки или null.
     */
    public static function check(string $password): ?string
    {
        if (mb_strlen($password) < self::MIN_LENGTH) {
            return 'Пароль должен быть не короче ' . self::MIN_LENGTH . ' символов';
        }

        return null;
    }

    /**
     * Пора ли пересчитать хеш — алгоритм по умолчанию в PHP со временем меняется.
     */
    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_DEFAULT);
    }

    /**
     * Пароль для нового пользователя, когда его не задали руками.
     */
    public static function generate(int $length = 12): string
    {
        return Str::random(max($length, self::MIN_LENGTH));
    }
}
