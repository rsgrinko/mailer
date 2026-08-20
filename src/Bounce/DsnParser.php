<?php

declare(strict_types=1);

namespace Mailer\Bounce;

use Mailer\Message\MimeParser;

/**
 * Разбор письма-отказа.
 *
 * Почтовые серверы отвечают отчётом по RFC 3464: `multipart/report` с частью
 * `message/delivery-status`, где на каждого получателя записано, что с ним случилось.
 * Оттуда и берём адрес, код и текст ответа принимающей стороны.
 *
 * Если отчёта нет (а такое бывает — некоторые серверы шлют просто письмо «не доставлено»),
 * пытаемся понять хотя бы, о каком нашем письме речь: адрес отказа с VERP это скажет.
 */
final class DsnParser
{
    /**
     * Разбирает письмо. Возвращает идентификатор нашего письма (если известен) и
     * список получателей, по которым пришёл ответ.
     *
     * @return array{uuid: ?string, recipients: array<int, array{email: string, status: string, action: string, diagnostic: string, permanent: bool}>}
     */
    public static function parse(string $raw): array
    {
        return [
            'uuid'       => self::uuid($raw),
            'recipients' => self::recipients($raw),
        ];
    }

    /**
     * Идентификатор нашего письма: из адреса, на который пришёл отказ (VERP), а если
     * VERP не используется — из вложенного оригинала по Message-ID.
     */
    private static function uuid(string $raw): ?string
    {
        $headers = MimeParser::headers($raw);

        // Куда пришёл отказ: то, что подставил сервер, живёт в разных заголовках
        foreach (['delivered-to', 'x-original-to', 'to', 'envelope-to'] as $name) {
            foreach ((array) ($headers[$name] ?? []) as $value) {
                foreach (self::emails((string) $value) as $email) {
                    $uuid = Verp::uuid($email);

                    if ($uuid !== null) {
                        return $uuid;
                    }
                }
            }
        }

        // Message-ID вида <uuid@домен> — его ставит наш же сборщик письма
        if (preg_match('/^Message-ID:\s*<([0-9a-f-]{36})@/mi', $raw, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Получатели из отчёта о доставке.
     *
     * @return array<int, array{email: string, status: string, action: string, diagnostic: string, permanent: bool}>
     */
    private static function recipients(string $raw): array
    {
        $result = [];

        foreach (self::deliveryStatusBlocks($raw) as $block) {
            $email = self::field($block, 'Final-Recipient');
            if ($email === '') {
                $email = self::field($block, 'Original-Recipient');
            }

            $email = self::address($email);
            if ($email === '') {
                continue;
            }

            $status     = self::field($block, 'Status');
            $action     = mb_strtolower(self::field($block, 'Action'));
            $diagnostic = self::field($block, 'Diagnostic-Code');

            // Окончательным считаем отказ: временные задержки сервер шлёт как delayed
            $permanent = str_starts_with($status, '5') || $action === 'failed';

            $result[] = [
                'email'      => $email,
                'status'     => $status,
                'action'     => $action === '' ? ($permanent ? 'failed' : 'delayed') : $action,
                'diagnostic' => $diagnostic,
                'permanent'  => $permanent && $action !== 'delayed',
            ];
        }

        return $result;
    }

    /**
     * Куски письма с отчётом о доставке. Разбор идёт по границам multipart, а не
     * через MimeParser::parse(): тому нужны текст и вложения, а нам — служебная часть.
     *
     * @return array<int, string>
     */
    private static function deliveryStatusBlocks(string $raw): array
    {
        $blocks = [];

        // В отчёте на каждого получателя свой абзац, между ними пустая строка
        foreach (self::parts($raw) as $part) {
            [$head, $body] = MimeParser::split($part);
            $headers       = MimeParser::headers($head . "\r\n\r\n");
            $type          = mb_strtolower(trim(explode(';', (string) ($headers['content-type'][0] ?? ''))[0]));

            if ($type !== 'message/delivery-status') {
                continue;
            }

            foreach (preg_split('/\R{2,}/', $body) ?: [] as $paragraph) {
                if (stripos($paragraph, 'Final-Recipient') !== false
                    || stripos($paragraph, 'Original-Recipient') !== false) {
                    $blocks[] = $paragraph;
                }
            }
        }

        return $blocks;
    }

    /**
     * Части составного письма. Вложенные multipart тоже разбираем: отчёт нередко
     * лежит внутри multipart/mixed.
     *
     * @return array<int, string>
     */
    private static function parts(string $raw): array
    {
        $headers  = MimeParser::headers($raw);
        $type     = (string) ($headers['content-type'][0] ?? '');
        $boundary = MimeParser::parameter($type, 'boundary');

        if ($boundary === '') {
            return [];
        }

        [, $body] = MimeParser::split($raw);

        $parts = [];
        foreach (self::splitByBoundary($body, $boundary) as $part) {
            $parts[] = $part;

            // Внутри может быть ещё один уровень — там же лежит нужный нам отчёт
            $inner = MimeParser::headers(MimeParser::split($part)[0] . "\r\n\r\n");
            if (str_starts_with(mb_strtolower((string) ($inner['content-type'][0] ?? '')), 'multipart/')) {
                foreach (self::parts($part) as $nested) {
                    $parts[] = $nested;
                }
            }
        }

        return $parts;
    }

    /**
     * Разбивает тело по границе multipart.
     *
     * @return array<int, string>
     */
    private static function splitByBoundary(string $body, string $boundary): array
    {
        $chunks = preg_split('/\R--' . preg_quote($boundary, '/') . '(--)?\R?/', "\r\n" . $body) ?: [];

        $parts = [];
        foreach ($chunks as $index => $chunk) {
            // Первый кусок — преамбула до первой границы, последний — хвост после закрывающей
            if ($index === 0 || trim($chunk) === '') {
                continue;
            }

            $parts[] = $chunk;
        }

        return $parts;
    }

    /**
     * Значение поля отчёта: `Status: 5.1.1`.
     */
    private static function field(string $block, string $name): string
    {
        if (preg_match('/^' . preg_quote($name, '/') . ':\s*(.+)$/mi', $block, $matches) !== 1) {
            return '';
        }

        return trim($matches[1]);
    }

    /**
     * Адрес из значения вида `rfc822; ivan@example.com`.
     */
    private static function address(string $value): string
    {
        if ($value === '') {
            return '';
        }

        $parts = explode(';', $value, 2);
        $email = trim(count($parts) === 2 ? $parts[1] : $parts[0]);

        return self::emails($email)[0] ?? '';
    }

    /**
     * Все адреса из строки.
     *
     * @return array<int, string>
     */
    private static function emails(string $value): array
    {
        if (preg_match_all('/[\w.+\-!#$%&\'*\/=?^`{|}~]+@[\w.\-]+\.[a-z]{2,}/iu', $value, $matches) !== false) {
            return array_values(array_map(static fn (string $email): string => trim($email, '<>'), $matches[0]));
        }

        return [];
    }
}
