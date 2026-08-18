<?php

declare(strict_types=1);

namespace Mailer\Message;

/**
 * Кодирование по правилам почты: заголовки в UTF-8, тела в base64 или quoted-printable.
 */
final class Encoder
{
    public const CRLF = "\r\n";

    /**
     * Заголовок с русским текстом надо закодировать (RFC 2047).
     * Если текст полностью латинский и без спецсимволов — оставляем как есть.
     */
    public static function header(string $value): string
    {
        $value = str_replace(["\r", "\n"], ' ', trim($value));

        if ($value === '' || preg_match('/^[\x20-\x7E]*$/', $value) === 1) {
            return $value;
        }

        // Режем по 45 символов: после base64 строка останется в пределах 76 байт
        $parts  = [];
        $length = mb_strlen($value, 'UTF-8');
        for ($i = 0; $i < $length; $i += 15) {
            $parts[] = '=?UTF-8?B?' . base64_encode(mb_substr($value, $i, 15, 'UTF-8')) . '?=';
        }

        return implode(self::CRLF . ' ', $parts);
    }

    /**
     * Тело письма в quoted-printable — так текст остаётся читаемым в исходнике.
     */
    public static function quotedPrintable(string $text): string
    {
        $text = self::normalizeLineEndings($text);

        return quoted_printable_encode($text);
    }

    /**
     * Base64 с переносами по 76 символов — так требует стандарт.
     */
    public static function base64(string $data): string
    {
        return rtrim(chunk_split(base64_encode($data), 76, self::CRLF));
    }

    /**
     * Приводим переводы строк к CRLF: SMTP другого не понимает.
     */
    public static function normalizeLineEndings(string $text): string
    {
        return preg_replace("/\r\n|\r|\n/", self::CRLF, $text) ?? $text;
    }

    /**
     * Экранирование точки в начале строки — иначе SMTP решит, что письмо закончилось.
     */
    public static function dotStuff(string $data): string
    {
        $data = self::normalizeLineEndings($data);

        if (str_starts_with($data, '.')) {
            $data = '.' . $data;
        }

        return str_replace(self::CRLF . '.', self::CRLF . '..', $data);
    }

    /**
     * Имя файла для заголовка Content-Disposition (RFC 2231, чтобы не ломались русские имена).
     */
    public static function fileName(string $name): string
    {
        $name = str_replace(['"', "\r", "\n"], '', $name);

        if (preg_match('/^[\x20-\x7E]*$/', $name) === 1) {
            return 'filename="' . $name . '"';
        }

        return 'filename*=UTF-8\'\'' . rawurlencode($name);
    }
}
