<?php

declare(strict_types=1);

namespace Mailer\Message;

/**
 * Письмо в том виде, в каком с ним работает сервис.
 * Может быть собрано из полей (обычный путь) или содержать готовый MIME —
 * так приходят письма из sendmail-shim и SMTP-релея.
 */
final class Message
{
    public ?Address $from = null;

    /** @var array<int, Address> */
    public array $to = [];

    /** @var array<int, Address> */
    public array $cc = [];

    /** @var array<int, Address> */
    public array $bcc = [];

    public ?Address $replyTo = null;

    public string $subject = '';

    public string $text = '';

    public string $html = '';

    /** @var array<string, string> Дополнительные заголовки */
    public array $headers = [];

    /** @var array<int, Attachment> */
    public array $attachments = [];

    /** Готовое письмо целиком (если пришло извне) */
    public ?string $raw = null;

    /** Отправитель конверта — то, что уйдёт в MAIL FROM */
    public ?string $envelopeFrom = null;

    /** @var array<int, string> Получатели конверта — то, что уйдёт в RCPT TO */
    public array $envelopeTo = [];

    public ?string $messageId = null;

    /** Меньше — важнее. Воркер берёт письма по возрастанию */
    public int $priority = 100;

    public ?string $tag = null;

    /** @var array<string, mixed> Произвольные данные клиента */
    public array $meta = [];

    /**
     * Все получатели письма (To + Cc + Bcc) — им реально отправляем.
     *
     * @return array<int, string>
     */
    public function recipients(): array
    {
        if ($this->envelopeTo !== []) {
            return array_values(array_unique($this->envelopeTo));
        }

        $emails = [];
        foreach ([...$this->to, ...$this->cc, ...$this->bcc] as $address) {
            $emails[] = $address->email;
        }

        return array_values(array_unique($emails));
    }

    /**
     * Адрес в MAIL FROM.
     */
    public function sender(): string
    {
        if ($this->envelopeFrom !== null && $this->envelopeFrom !== '') {
            return $this->envelopeFrom;
        }

        return $this->from?->email ?? '';
    }

    public function addTo(string $email, string $name = ''): self
    {
        $this->to[] = new Address($email, $name);

        return $this;
    }

    public function addCc(string $email, string $name = ''): self
    {
        $this->cc[] = new Address($email, $name);

        return $this;
    }

    public function addBcc(string $email, string $name = ''): self
    {
        $this->bcc[] = new Address($email, $name);

        return $this;
    }

    public function attach(Attachment $attachment): self
    {
        $this->attachments[] = $attachment;

        return $this;
    }

    /**
     * Есть ли картинки, вставленные прямо в HTML.
     */
    public function hasInlineAttachments(): bool
    {
        foreach ($this->attachments as $attachment) {
            if ($attachment->inline) {
                return true;
            }
        }

        return false;
    }

    /**
     * Примерный размер письма — нужен для проверки лимитов.
     */
    public function approximateSize(): int
    {
        if ($this->raw !== null) {
            return strlen($this->raw);
        }

        $size = strlen($this->subject) + strlen($this->text) + strlen($this->html);
        foreach ($this->attachments as $attachment) {
            // base64 раздувает вложение примерно на треть
            $size += (int) ($attachment->size() * 1.37);
        }

        return $size;
    }
}
