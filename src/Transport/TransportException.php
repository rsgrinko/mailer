<?php

declare(strict_types=1);

namespace Mailer\Transport;

use Mailer\Support\MailerException;
use Throwable;

/**
 * Ошибка отправки. Главное здесь — временная она или окончательная:
 * временную письмо переживёт и будет отправлено позже, окончательная сразу помечает
 * письмо как неудачное.
 */
final class TransportException extends MailerException
{
    private bool $temporary;

    /**
     * @param array<string, mixed> $context
     */
    public function __construct(string $message, bool $temporary = false, array $context = [], ?Throwable $previous = null)
    {
        parent::__construct($message, $context, 0, $previous);

        $this->temporary = $temporary;
    }

    /**
     * Временная ошибка: сеть отвалилась, сервер ответил 4xx, превышен лимит.
     */
    public static function temporary(string $message, array $context = [], ?Throwable $previous = null): self
    {
        return new self($message, true, $context, $previous);
    }

    /**
     * Окончательная ошибка: неверный адрес, отказ сервера 5xx, ошибка авторизации.
     */
    public static function permanent(string $message, array $context = [], ?Throwable $previous = null): self
    {
        return new self($message, false, $context, $previous);
    }

    public function isTemporary(): bool
    {
        return $this->temporary;
    }
}
