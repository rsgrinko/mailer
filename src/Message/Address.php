<?php

declare(strict_types=1);

namespace Mailer\Message;

use Mailer\Support\MailerException;
use Mailer\Support\Validator;

/**
 * Почтовый адрес: e-mail и необязательное имя.
 */
final class Address
{
    public string $email;
    public string $name;

    public function __construct(string $email, string $name = '')
    {
        $email = trim($email);

        if (!Validator::isEmail($email)) {
            throw new MailerException('Некорректный e-mail: ' . $email);
        }

        $this->email = $email;
        $this->name  = trim($name);
    }

    /**
     * Разбирает адрес из строки: "Иван <ivan@example.com>" или просто "ivan@example.com".
     */
    public static function parse(string $value): self
    {
        $value = trim($value);

        if (preg_match('/^(.*)<([^<>]+)>\s*$/u', $value, $m) === 1) {
            $name = trim($m[1]);
            $name = trim($name, " \t\"'");

            return new self(trim($m[2]), self::decodeName($name));
        }

        return new self($value);
    }

    /**
     * Принимает что угодно из API: строку, массив ['email' => ..., 'name' => ...],
     * список строк или список массивов. На выходе — список адресов.
     *
     * @return array<int, self>
     */
    public static function parseList(mixed $value): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }

        if (is_string($value)) {
            // Строку с запятыми разбираем аккуратно: запятая может быть внутри имени в кавычках
            $value = self::splitList($value);
        }

        if (is_array($value) && isset($value['email'])) {
            $value = [$value];
        }

        $result = [];
        foreach ((array) $value as $item) {
            if (is_array($item)) {
                $email = (string) ($item['email'] ?? '');
                $name  = (string) ($item['name'] ?? '');
                $result[] = new self($email, $name);
                continue;
            }

            $item = trim((string) $item);
            if ($item !== '') {
                $result[] = self::parse($item);
            }
        }

        return $result;
    }

    /**
     * Разбивает строку с несколькими адресами по запятым, не трогая запятые в кавычках.
     *
     * @return array<int, string>
     */
    public static function splitList(string $value): array
    {
        $parts   = [];
        $current = '';
        $inQuote = false;
        $inAngle = false;

        foreach (str_split($value) as $char) {
            if ($char === '"') {
                $inQuote = !$inQuote;
            } elseif ($char === '<') {
                $inAngle = true;
            } elseif ($char === '>') {
                $inAngle = false;
            }

            if (($char === ',' || $char === ';') && !$inQuote && !$inAngle) {
                $parts[] = $current;
                $current = '';
                continue;
            }

            $current .= $char;
        }

        $parts[] = $current;

        return array_values(array_filter(array_map('trim', $parts), static fn (string $p): bool => $p !== ''));
    }

    /**
     * Готовая строка для заголовка письма.
     */
    public function format(): string
    {
        if ($this->name === '') {
            return $this->email;
        }

        return Encoder::header($this->name) . ' <' . $this->email . '>';
    }

    /**
     * Как показывать адрес человеку (в панели, логах).
     */
    public function display(): string
    {
        return $this->name === '' ? $this->email : $this->name . ' <' . $this->email . '>';
    }

    /**
     * @return array{email: string, name: string}
     */
    public function toArray(): array
    {
        return ['email' => $this->email, 'name' => $this->name];
    }

    /**
     * Склеивает список адресов в значение заголовка.
     *
     * @param array<int, self> $addresses
     */
    public static function formatList(array $addresses): string
    {
        return implode(', ', array_map(static fn (self $a): string => $a->format(), $addresses));
    }

    /**
     * Раскодирует имя из =?UTF-8?B?...?= — встречается в письмах из sendmail и SMTP-релея.
     */
    public static function decodeName(string $name): string
    {
        if (!str_contains($name, '=?')) {
            return $name;
        }

        $decoded = iconv_mime_decode($name, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');

        return $decoded === false ? $name : $decoded;
    }
}
