<?php

declare(strict_types=1);

namespace Mailer\Support;

/**
 * Мелкие строковые помощники, которые нужны в разных местах сервиса.
 */
final class Str
{
    /**
     * UUID версии 4 — им помечаем письма.
     */
    public static function uuid(): string
    {
        $bytes = random_bytes(16);

        // Проставляем версию (4) и вариант (10xx) по стандарту
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }

    /**
     * Случайная строка из безопасного алфавита (без похожих символов).
     */
    public static function random(int $length = 32): string
    {
        $alphabet = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $max      = strlen($alphabet) - 1;
        $result   = '';

        for ($i = 0; $i < $length; $i++) {
            $result .= $alphabet[random_int(0, $max)];
        }

        return $result;
    }

    /**
     * Грубое, но рабочее превращение HTML в текст — для писем, где прислали только HTML.
     */
    public static function htmlToText(string $html): string
    {
        $text = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $html) ?? $html;
        $text = preg_replace('#<br\s*/?>#i', "\n", $text) ?? $text;
        $text = preg_replace('#</(p|div|tr|h[1-6]|li)>#i', "\n", $text) ?? $text;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+/", ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * Обрезает строку до нужной длины, добавляя многоточие.
     */
    public static function limit(string $value, int $limit = 100): string
    {
        if (mb_strlen($value, 'UTF-8') <= $limit) {
            return $value;
        }

        return mb_substr($value, 0, $limit - 1, 'UTF-8') . '…';
    }

    /**
     * Человекочитаемый размер файла.
     */
    public static function bytes(int $size): string
    {
        $units = ['Б', 'КБ', 'МБ', 'ГБ'];
        $index = 0;
        $value = (float) $size;

        while ($value >= 1024 && $index < count($units) - 1) {
            $value /= 1024;
            $index++;
        }

        return ($index === 0 ? (string) (int) $value : number_format($value, 1, ',', ' ')) . ' ' . $units[$index];
    }

    /**
     * Сравнение строк без утечки времени (для API-ключей и подписей).
     */
    public static function secureEquals(string $known, string $given): bool
    {
        return hash_equals($known, $given);
    }
}
