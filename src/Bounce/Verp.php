<?php

declare(strict_types=1);

namespace Mailer\Bounce;

use Mailer\Support\Config;

/**
 * Адрес для отказов вида `bounce+<uuid>@домен` (VERP).
 *
 * Смысл простой: письмо уходит с обратным адресом, в который вшит его идентификатор.
 * Сервер получателя вернёт отказ на этот адрес, и по нему сразу понятно, о каком
 * письме речь, — не нужно гадать по теме и заголовкам отчёта.
 *
 * Работает не везде: почтовые службы вроде Яндекса принимают в MAIL FROM только адрес
 * своего аккаунта, поэтому VERP включают, когда отправка идёт со своего сервера.
 */
final class Verp
{
    /** Разделитель между ящиком и идентификатором. Плюс понимают все почтовые серверы */
    private const SEPARATOR = '+';

    /**
     * Обратный адрес для письма. Пустая строка — VERP выключен или адрес не настроен.
     */
    public static function address(string $uuid): string
    {
        $base = trim((string) Config::get('bounce.address', ''));

        if ($base === '' || $uuid === '' || !(bool) Config::get('bounce.verp', false)) {
            return '';
        }

        [$mailbox, $domain] = self::split($base);

        if ($mailbox === '' || $domain === '') {
            return '';
        }

        return $mailbox . self::SEPARATOR . $uuid . '@' . $domain;
    }

    /**
     * Достаёт идентификатор письма из адреса отказа. Null — адрес не наш.
     */
    public static function uuid(string $address): ?string
    {
        $address = trim($address);
        $base    = trim((string) Config::get('bounce.address', ''));

        if ($address === '' || $base === '') {
            return null;
        }

        [$mailbox, $domain] = self::split($base);
        [$gotBox, $gotDomain] = self::split($address);

        if ($mailbox === '' || strcasecmp($domain, $gotDomain) !== 0) {
            return null;
        }

        $prefix = $mailbox . self::SEPARATOR;

        if (!str_starts_with(mb_strtolower($gotBox), mb_strtolower($prefix))) {
            return null;
        }

        $uuid = substr($gotBox, strlen($prefix));

        return $uuid === '' ? null : $uuid;
    }

    /**
     * Ящик и домен адреса.
     *
     * @return array{0: string, 1: string}
     */
    private static function split(string $address): array
    {
        $at = strrpos($address, '@');

        if ($at === false) {
            return ['', ''];
        }

        return [substr($address, 0, $at), substr($address, $at + 1)];
    }
}
