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
        $this->applyDefaultSender($message);

        $mime = (new MimeBuilder())->build($message);

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
     * Если письмо пришло без отправителя — подставляем адрес из настроек транспорта.
     */
    protected function applyDefaultSender(Message $message): void
    {
        if ($message->from !== null) {
            return;
        }

        $email = (string) $this->setting('from_email', '');
        if ($email === '') {
            return;
        }

        $message->from = new Address($email, (string) $this->setting('from_name', ''));
    }
}
