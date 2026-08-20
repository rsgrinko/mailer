<?php

declare(strict_types=1);

namespace Mailer\Transport;

use Mailer\Dkim\Signer;
use Mailer\Message\Address;
use Mailer\Message\Message;
use Mailer\Message\MimeBuilder;
use Mailer\Message\MimeParser;

/**
 * Общая часть всех транспортов: настройки, подстановка отправителя по умолчанию
 * и сборка письма (включая DKIM-подпись, если она настроена).
 */
abstract class BaseTransport implements TransportInterface
{
    protected string $name;

    /** @var array<string, mixed> Настройки транспорта из базы */
    protected array $settings;

    /**
     * @param array<string, mixed> $settings
     */
    public function __construct(string $name, array $settings = [])
    {
        $this->name     = $name;
        $this->settings = $settings;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function test(): string
    {
        return 'Транспорт «' . $this->name . '» (' . $this->type() . ') настроек для проверки не требует';
    }

    /**
     * Значение настройки транспорта.
     */
    protected function setting(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }

    /**
     * Готовое письмо: собираем MIME и при необходимости подписываем DKIM.
     */
    protected function render(Message $message): string
    {
        $original = $message->from;
        $replaced = $this->applyDefaultSender($message);

        $mime = (new MimeBuilder())->build($message);

        // Готовое письмо MimeBuilder отдаёт как есть, поэтому подменённого
        // отправителя правим прямо в его заголовках
        if ($replaced && $message->raw !== null && $message->from !== null) {
            $mime = MimeParser::setHeader($mime, 'From', $message->from->format());

            if ($original !== null && $message->replyTo !== null) {
                $mime = MimeParser::setHeader($mime, 'Reply-To', $message->replyTo->format());
            }
        }

        // В самом письме скрытых получателей быть не должно
        $mime = MimeParser::removeHeader($mime, 'Bcc');

        $dkim = $this->setting('dkim');
        if (is_array($dkim) && ($dkim['enabled'] ?? false) === true) {
            $signer = new Signer(
                (string) ($dkim['domain'] ?? ''),
                (string) ($dkim['selector'] ?? 'mail'),
                (string) ($dkim['private_key'] ?? ''),
                (array) ($dkim['headers'] ?? [])
            );

            $mime = $signer->sign($mime);
        }

        return $mime;
    }

    /**
     * Отправитель от транспорта. Обычно подставляется только там, где его нет,
     * но с настройкой force_from — всегда: Яндекс и подобные шлют письма лишь от
     * имени своего аккаунта и отвергают чужой адрес ещё на MAIL FROM.
     *
     * @return bool подменили ли отправителя, который был в письме
     */
    protected function applyDefaultSender(Message $message): bool
    {
        $email = trim((string) $this->setting('from_email', ''));
        if ($email === '') {
            return false;
        }

        $force = (bool) $this->setting('force_from', false);

        if ($message->from !== null && !$force) {
            return false;
        }

        $original = $message->from;

        // Уже тот самый адрес — трогать нечего, только конверт приведём к нему
        if ($original !== null && strcasecmp($original->email, $email) === 0) {
            $message->envelopeFrom = $email;

            return false;
        }

        // Ответ должен уходить автору письма, а не на аккаунт транспорта
        if ($original !== null && $message->replyTo === null) {
            $message->replyTo = $original;
        }

        $message->from         = new Address($email, (string) $this->setting('from_name', ''));
        $message->envelopeFrom = $email;

        return $original !== null;
    }
}
