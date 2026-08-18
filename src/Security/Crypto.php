<?php

declare(strict_types=1);

namespace Mailer\Security;

use Mailer\Support\Config;
use Mailer\Support\MailerException;

/**
 * Шифрование паролей SMTP, которые лежат в базе (AES-256-GCM).
 * Ключ берётся из APP_KEY в .env, создать его можно командой `php bin/mailer app:key`.
 *
 * Если ключа нет, значение сохраняется как есть — сервис продолжает работать,
 * но в панели и в CLI об этом будет предупреждение.
 */
final class Crypto
{
    private const CIPHER = 'aes-256-gcm';
    private const PREFIX = 'enc:v1:';

    /**
     * Зашифровать строку.
     */
    public static function encrypt(string $value): string
    {
        if ($value === '') {
            return '';
        }

        $key = self::key();
        if ($key === null) {
            return $value;
        }

        $iv  = random_bytes(12);
        $tag = '';

        $cipher = openssl_encrypt($value, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new MailerException('Не удалось зашифровать значение');
        }

        return self::PREFIX . base64_encode($iv . $tag . $cipher);
    }

    /**
     * Расшифровать строку. Незашифрованные значения возвращаются как есть.
     */
    public static function decrypt(string $value): string
    {
        if ($value === '' || !str_starts_with($value, self::PREFIX)) {
            return $value;
        }

        $key = self::key();
        if ($key === null) {
            throw new MailerException('В .env не задан APP_KEY, а в базе есть зашифрованные значения');
        }

        $data = base64_decode(substr($value, strlen(self::PREFIX)), true);
        if ($data === false || strlen($data) < 29) {
            throw new MailerException('Зашифрованное значение повреждено');
        }

        $iv     = substr($data, 0, 12);
        $tag    = substr($data, 12, 16);
        $cipher = substr($data, 28);

        $plain = openssl_decrypt($cipher, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) {
            throw new MailerException('Не удалось расшифровать значение: неверный APP_KEY?');
        }

        return $plain;
    }

    /**
     * Есть ли ключ шифрования.
     */
    public static function hasKey(): bool
    {
        return self::key() !== null;
    }

    /**
     * Новый случайный ключ для .env.
     */
    public static function generateKey(): string
    {
        return 'base64:' . base64_encode(random_bytes(32));
    }

    /**
     * Бинарный ключ из настройки APP_KEY.
     */
    private static function key(): ?string
    {
        $raw = (string) Config::get('app.key', '');
        if ($raw === '') {
            return null;
        }

        if (str_starts_with($raw, 'base64:')) {
            $decoded = base64_decode(substr($raw, 7), true);
            if ($decoded !== false && strlen($decoded) === 32) {
                return $decoded;
            }
        }

        // Ключ произвольной длины приводим к 32 байтам
        return hash('sha256', $raw, true);
    }
}
