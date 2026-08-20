<?php

declare(strict_types=1);

namespace Mailer\Message;

/**
 * Разбор готового MIME-письма. Нужен в двух местах:
 *  - когда письмо приходит извне (sendmail-shim, SMTP-релей) — достаём адреса и тему;
 *  - когда показываем письмо в панели — достаём текст, HTML и список вложений.
 */
final class MimeParser
{
    /**
     * Делит письмо на «шапку» и тело.
     *
     * @return array{0: string, 1: string}
     */
    public static function split(string $raw): array
    {
        $raw = Encoder::normalizeLineEndings($raw);
        $pos = strpos($raw, "\r\n\r\n");

        if ($pos === false) {
            return [$raw, ''];
        }

        return [substr($raw, 0, $pos), substr($raw, $pos + 4)];
    }

    /**
     * Все заголовки письма. Ключи приводим к нижнему регистру,
     * значения раскодируем из =?UTF-8?B?...?=.
     *
     * @return array<string, array<int, string>>
     */
    public static function headers(string $raw): array
    {
        [$head] = self::split($raw);

        $headers = [];
        $name    = '';

        foreach (explode("\r\n", $head) as $line) {
            if ($line === '') {
                continue;
            }

            // Продолжение предыдущего заголовка — строка начинается с пробела
            if (($line[0] === ' ' || $line[0] === "\t") && $name !== '') {
                $last = array_key_last($headers[$name]);
                $headers[$name][$last] .= ' ' . trim($line);
                continue;
            }

            if (!str_contains($line, ':')) {
                continue;
            }

            [$rawName, $value] = explode(':', $line, 2);
            $name              = strtolower(trim($rawName));
            $headers[$name][]  = trim($value);
        }

        foreach ($headers as $key => $values) {
            $headers[$key] = array_map(static fn (string $v): string => Address::decodeName($v), $values);
        }

        return $headers;
    }

    /**
     * Значение одного заголовка (первое, если их несколько).
     */
    public static function header(string $raw, string $name): string
    {
        $headers = self::headers($raw);

        return $headers[strtolower($name)][0] ?? '';
    }

    /**
     * Убирает заголовок из письма. Нужно для Bcc: скрытые получатели
     * не должны попасть в то, что реально уйдёт адресатам.
     */
    public static function removeHeader(string $raw, string $name): string
    {
        [$head, $body] = self::split($raw);

        $result = [];
        $skip   = false;

        foreach (explode("\r\n", $head) as $line) {
            if ($line !== '' && ($line[0] === ' ' || $line[0] === "\t")) {
                if (!$skip) {
                    $result[] = $line;
                }
                continue;
            }

            $skip = str_starts_with(strtolower($line), strtolower($name) . ':');
            if (!$skip) {
                $result[] = $line;
            }
        }

        return implode("\r\n", $result) . "\r\n\r\n" . $body;
    }

    /**
     * Заменяет заголовок в готовом письме: старый убирается, новый встаёт первым.
     * Нужно транспорту, который подменяет отправителя в уже собранном письме.
     */
    public static function setHeader(string $raw, string $name, string $value): string
    {
        [$head, $body] = self::split(self::removeHeader($raw, $name));

        $head = trim($head, "\r\n");
        $line = $name . ': ' . $value;

        return ($head === '' ? $line : $line . "\r\n" . $head) . "\r\n\r\n" . $body;
    }

    /**
     * Разбирает письмо целиком.
     *
     * @return array{
     *     headers: array<string, array<int, string>>,
     *     subject: string,
     *     from: array<int, Address>,
     *     to: array<int, Address>,
     *     cc: array<int, Address>,
     *     bcc: array<int, Address>,
     *     text: string,
     *     html: string,
     *     attachments: array<int, array<string, mixed>>
     * }
     */
    public static function parse(string $raw): array
    {
        $headers = self::headers($raw);
        [, $body] = self::split($raw);

        $result = [
            'headers'     => $headers,
            'subject'     => $headers['subject'][0] ?? '',
            'from'        => self::addresses($headers['from'] ?? []),
            'to'          => self::addresses($headers['to'] ?? []),
            'cc'          => self::addresses($headers['cc'] ?? []),
            'bcc'         => self::addresses($headers['bcc'] ?? []),
            'text'        => '',
            'html'        => '',
            'attachments' => [],
        ];

        self::walkPart(
            $headers['content-type'][0] ?? 'text/plain',
            $headers['content-transfer-encoding'][0] ?? '',
            $headers['content-disposition'][0] ?? '',
            $body,
            $result
        );

        return $result;
    }

    /**
     * Рекурсивно обходит части письма и раскладывает их по text / html / вложения.
     *
     * @param array<string, mixed> $result
     */
    private static function walkPart(
        string $contentType,
        string $encoding,
        string $disposition,
        string $body,
        array &$result
    ): void {
        $type = strtolower(trim(explode(';', $contentType)[0]));

        if (str_starts_with($type, 'multipart/')) {
            $boundary = self::parameter($contentType, 'boundary');
            if ($boundary === '') {
                return;
            }

            foreach (self::splitByBoundary($body, $boundary) as $part) {
                [$partHead, $partBody] = self::split($part);
                $partHeaders           = self::headers($partHead . "\r\n\r\n");

                self::walkPart(
                    $partHeaders['content-type'][0] ?? 'text/plain',
                    $partHeaders['content-transfer-encoding'][0] ?? '',
                    $partHeaders['content-disposition'][0] ?? '',
                    $partBody,
                    $result
                );
            }

            return;
        }

        $isAttachment = str_starts_with(strtolower($disposition), 'attachment')
            || self::parameter($disposition, 'filename') !== ''
            || self::parameter($contentType, 'name') !== '';

        if ($isAttachment) {
            $name = self::parameter($disposition, 'filename');
            if ($name === '') {
                $name = self::parameter($contentType, 'name');
            }

            $result['attachments'][] = [
                'name'         => $name !== '' ? $name : 'file',
                'content_type' => $type,
                'inline'       => str_starts_with(strtolower($disposition), 'inline'),
                'size'         => strlen(self::decode($body, $encoding)),
            ];

            return;
        }

        $decoded = self::decode($body, $encoding);
        $charset = self::parameter($contentType, 'charset');
        if ($charset !== '' && strtoupper($charset) !== 'UTF-8') {
            $converted = @iconv($charset, 'UTF-8//TRANSLIT', $decoded);
            if ($converted !== false) {
                $decoded = $converted;
            }
        }

        if ($type === 'text/html') {
            $result['html'] .= $decoded;
        } elseif (str_starts_with($type, 'text/')) {
            $result['text'] .= $decoded;
        }
    }

    /**
     * Режет тело multipart на части по границе.
     *
     * @return array<int, string>
     */
    private static function splitByBoundary(string $body, string $boundary): array
    {
        $parts  = explode('--' . $boundary, $body);
        $result = [];

        // Первый кусок — преамбула, последний — хвост после закрывающей границы
        array_shift($parts);
        foreach ($parts as $part) {
            if (str_starts_with(trim($part), '--')) {
                break;
            }
            $result[] = ltrim($part, "\r\n");
        }

        return $result;
    }

    /**
     * Значение параметра из заголовка: boundary, charset, filename и т.п.
     */
    public static function parameter(string $header, string $name): string
    {
        // Обычный вид: name="значение" или name=значение
        if (preg_match('/;\s*' . preg_quote($name, '/') . '\s*=\s*("([^"]*)"|([^;\s]+))/i', $header, $m) === 1) {
            return $m[2] !== '' ? $m[2] : ($m[3] ?? '');
        }

        // Вид RFC 2231: name*=UTF-8''%D0%98%D0%BC%D1%8F
        if (preg_match('/;\s*' . preg_quote($name, '/') . '\*\s*=\s*([^\';]*)\'[^\']*\'([^;\s]+)/i', $header, $m) === 1) {
            $decoded = rawurldecode($m[2]);
            if (strtoupper($m[1]) !== 'UTF-8' && $m[1] !== '') {
                $converted = @iconv($m[1], 'UTF-8//TRANSLIT', $decoded);
                if ($converted !== false) {
                    $decoded = $converted;
                }
            }

            return $decoded;
        }

        return '';
    }

    /**
     * Раскодировать содержимое части по её Content-Transfer-Encoding.
     */
    public static function decode(string $body, string $encoding): string
    {
        return match (strtolower(trim($encoding))) {
            'base64'           => (string) base64_decode($body, false),
            'quoted-printable' => quoted_printable_decode($body),
            default            => $body,
        };
    }

    /**
     * Превращает значения заголовков с адресами в объекты Address.
     * Кривые адреса просто пропускаем — письмо из-за них терять не хочется.
     *
     * @param array<int, string> $values
     * @return array<int, Address>
     */
    private static function addresses(array $values): array
    {
        $result = [];

        foreach ($values as $value) {
            foreach (Address::splitList($value) as $item) {
                try {
                    $result[] = Address::parse($item);
                } catch (\Throwable) {
                    continue;
                }
            }
        }

        return $result;
    }
}
