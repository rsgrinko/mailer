<?php

declare(strict_types=1);

namespace Mailer\Webhook;

/**
 * События, о которых сервис умеет сообщать наружу. Список лежит в коде рядом с
 * тем, кто их шлёт: заводить миграцию под каждое новое событие незачем, в базе
 * у подписки хранится только выбранный набор кодов.
 *
 * Имя события — «раздел.что случилось». Раздел нужен на вырост: когда появятся
 * события не про письмо, они не столкнутся с этими.
 */
final class Event
{
    /** Письмо принято в очередь */
    public const MESSAGE_QUEUED = 'message.queued';
    /** Письмо ушло получателю */
    public const MESSAGE_SENT = 'message.sent';
    /** Письмо отправить не удалось, попыток больше не будет */
    public const MESSAGE_FAILED = 'message.failed';
    /** Попытка не удалась, но письмо вернулось в очередь */
    public const MESSAGE_RETRY = 'message.retry';
    /** Письмо отменено до отправки */
    public const MESSAGE_CANCELED = 'message.canceled';
    /** Получатели закрыты стоп-листом */
    public const MESSAGE_SUPPRESSED = 'message.suppressed';
    /** Сервер получателя вернул отказ (отложенный, из ящика отказов) */
    public const MESSAGE_BOUNCED = 'message.bounced';
    /** Получатель нажал «отписаться». Письма за этим событием может и не быть */
    public const RECIPIENT_UNSUBSCRIBED = 'recipient.unsubscribed';
    /** Проверка связи из панели — настоящего письма за ним нет */
    public const PING = 'ping';

    /**
     * Все события с описанием. В этом же порядке они показываются в форме подписки.
     *
     * @var array<string, string>
     */
    public const LABELS = [
        self::MESSAGE_QUEUED       => 'Письмо принято в очередь',
        self::MESSAGE_SENT         => 'Письмо отправлено',
        self::MESSAGE_FAILED       => 'Письмо отправить не удалось',
        self::MESSAGE_RETRY        => 'Попытка не удалась, будет повтор',
        self::MESSAGE_CANCELED     => 'Письмо отменено',
        self::MESSAGE_SUPPRESSED   => 'Получатель в стоп-листе',
        self::MESSAGE_BOUNCED      => 'Отказ сервера получателя',
        self::RECIPIENT_UNSUBSCRIBED => 'Получатель отписался',
        self::PING                 => 'Проверка связи',
    ];

    /**
     * События, на которые можно подписаться в панели. Проверка связи в этот список
     * не входит: её шлют кнопкой, а не по подписке.
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        return array_values(array_filter(
            array_keys(self::LABELS),
            static fn (string $code): bool => $code !== self::PING
        ));
    }

    public static function known(string $code): bool
    {
        return isset(self::LABELS[$code]);
    }

    /**
     * Подпись для панели. Неизвестное показываем как есть — так видно, что в базе
     * осталась подписка на снятое событие.
     */
    public static function label(string $code): string
    {
        return self::LABELS[$code] ?? $code;
    }

    /**
     * Оставляет только известные события, без повторов и в порядке реестра.
     *
     * @param array<int, mixed> $codes
     * @return array<int, string>
     */
    public static function filter(array $codes): array
    {
        $codes = array_map(static fn (mixed $code): string => (string) $code, $codes);

        return array_values(array_filter(self::all(), static fn (string $code): bool => in_array($code, $codes, true)));
    }

    /**
     * Статус письма, в котором оно оказалось после события. Нужен телу вебхука:
     * принимающая сторона обычно хранит у себя именно статус.
     */
    public static function status(string $event): string
    {
        return match ($event) {
            self::MESSAGE_SENT       => 'sent',
            self::MESSAGE_FAILED     => 'failed',
            self::MESSAGE_CANCELED   => 'canceled',
            self::MESSAGE_SUPPRESSED => 'suppressed',
            self::MESSAGE_BOUNCED    => 'bounced',
            default                  => 'queued',
        };
    }
}
