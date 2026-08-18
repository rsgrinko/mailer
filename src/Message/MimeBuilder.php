<?php

declare(strict_types=1);

namespace Mailer\Message;

use Mailer\Support\Config;
use Mailer\Support\Str;

/**
 * Собирает из объекта Message готовое MIME-письмо.
 *
 * Схема частей:
 *   есть вложения            -> multipart/mixed
 *   есть картинки в HTML     -> multipart/related
 *   есть и текст, и HTML     -> multipart/alternative
 *   иначе                    -> одна часть text/plain или text/html
 */
final class MimeBuilder
{
    private const CRLF = Encoder::CRLF;

    /**
     * Возвращает письмо целиком: заголовки, пустая строка, тело.
     */
    public function build(Message $message): string
    {
        // Письмо пришло уже готовым (sendmail-shim, SMTP-релей) — ничего не трогаем
        if ($message->raw !== null) {
            return Encoder::normalizeLineEndings($message->raw);
        }

        [$body, $contentHeaders] = $this->buildBody($message);

        $headers = array_merge($this->buildHeaders($message), $contentHeaders);

        $lines = [];
        foreach ($headers as $name => $value) {
            if ($value === '') {
                continue;
            }
            $lines[] = $name . ': ' . $value;
        }

        return implode(self::CRLF, $lines) . self::CRLF . self::CRLF . $body;
    }

    /**
     * Заголовки письма (без Content-*, их добавляет сборка тела).
     *
     * @return array<string, string>
     */
    private function buildHeaders(Message $message): array
    {
        if ($message->messageId === null) {
            $message->messageId = $this->generateMessageId($message);
        }

        $headers = [
            'Date'         => date('r'),
            'Message-ID'   => $message->messageId,
            'MIME-Version' => '1.0',
        ];

        if ($message->from !== null) {
            $headers['From'] = $message->from->format();
        }

        if ($message->to !== []) {
            $headers['To'] = Address::formatList($message->to);
        }

        if ($message->cc !== []) {
            $headers['Cc'] = Address::formatList($message->cc);
        }

        if ($message->replyTo !== null) {
            $headers['Reply-To'] = $message->replyTo->format();
        }

        $headers['Subject']  = Encoder::header($message->subject);
        $headers['X-Mailer'] = (string) Config::get('app.name', 'Mailer');

        // Заголовки, которые задал клиент, идут последними и могут переопределить наши
        foreach ($message->headers as $name => $value) {
            $name = trim($name);
            if ($name === '' || $this->isForbiddenHeader($name)) {
                continue;
            }
            $headers[$name] = Encoder::header((string) $value);
        }

        return $headers;
    }

    /**
     * Bcc и Content-* клиенту задавать нельзя: первый выдал бы скрытых получателей,
     * вторые сломали бы структуру письма.
     */
    private function isForbiddenHeader(string $name): bool
    {
        $lower = strtolower($name);

        return $lower === 'bcc'
            || str_starts_with($lower, 'content-')
            || $lower === 'mime-version'
            || $lower === 'dkim-signature';
    }

    /**
     * Собирает тело письма.
     *
     * @return array{0: string, 1: array<string, string>} тело и его Content-заголовки
     */
    private function buildBody(Message $message): array
    {
        $regular = [];
        $inline  = [];
        foreach ($message->attachments as $attachment) {
            if ($attachment->inline) {
                $inline[] = $attachment;
            } else {
                $regular[] = $attachment;
            }
        }

        // Смысловая часть письма: текст, HTML или и то и другое
        [$content, $contentHeaders] = $this->buildContentPart($message);

        // Картинки внутри HTML заворачиваем в multipart/related
        if ($inline !== []) {
            $boundary = $this->boundary('rel');
            $parts    = [$this->part($contentHeaders, $content)];
            foreach ($inline as $attachment) {
                $parts[] = $this->attachmentPart($attachment);
            }

            $content        = $this->joinParts($parts, $boundary);
            $contentHeaders = ['Content-Type' => 'multipart/related; boundary="' . $boundary . '"'];
        }

        // Обычные вложения — внешний multipart/mixed
        if ($regular !== []) {
            $boundary = $this->boundary('mix');
            $parts    = [$this->part($contentHeaders, $content)];
            foreach ($regular as $attachment) {
                $parts[] = $this->attachmentPart($attachment);
            }

            $content        = $this->joinParts($parts, $boundary);
            $contentHeaders = ['Content-Type' => 'multipart/mixed; boundary="' . $boundary . '"'];
        }

        return [$content, $contentHeaders];
    }

    /**
     * Текстовая и/или HTML часть письма.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private function buildContentPart(Message $message): array
    {
        $text = $message->text;
        $html = $message->html;

        // Если прислали только HTML — сделаем текстовую версию сами: её ждут почтовые клиенты
        if ($text === '' && $html !== '') {
            $text = Str::htmlToText($html);
        }

        if ($html === '') {
            return [
                Encoder::quotedPrintable($text),
                [
                    'Content-Type'              => 'text/plain; charset=UTF-8',
                    'Content-Transfer-Encoding' => 'quoted-printable',
                ],
            ];
        }

        if ($text === '') {
            return [
                Encoder::quotedPrintable($html),
                [
                    'Content-Type'              => 'text/html; charset=UTF-8',
                    'Content-Transfer-Encoding' => 'quoted-printable',
                ],
            ];
        }

        $boundary = $this->boundary('alt');
        $parts    = [
            $this->part(
                [
                    'Content-Type'              => 'text/plain; charset=UTF-8',
                    'Content-Transfer-Encoding' => 'quoted-printable',
                ],
                Encoder::quotedPrintable($text)
            ),
            $this->part(
                [
                    'Content-Type'              => 'text/html; charset=UTF-8',
                    'Content-Transfer-Encoding' => 'quoted-printable',
                ],
                Encoder::quotedPrintable($html)
            ),
        ];

        return [
            $this->joinParts($parts, $boundary),
            ['Content-Type' => 'multipart/alternative; boundary="' . $boundary . '"'],
        ];
    }

    /**
     * Часть письма с вложением.
     */
    private function attachmentPart(Attachment $attachment): string
    {
        $headers = [
            'Content-Type'              => $attachment->contentType . '; name="' . $this->asciiName($attachment->name) . '"',
            'Content-Transfer-Encoding' => 'base64',
            'Content-Disposition'       => ($attachment->inline ? 'inline' : 'attachment') . '; ' . Encoder::fileName($attachment->name),
        ];

        if ($attachment->inline && $attachment->cid !== '') {
            $headers['Content-ID'] = '<' . $attachment->cid . '>';
        }

        return $this->part($headers, Encoder::base64($attachment->content()));
    }

    /**
     * Заголовки части + её содержимое.
     *
     * @param array<string, string> $headers
     */
    private function part(array $headers, string $body): string
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        return implode(self::CRLF, $lines) . self::CRLF . self::CRLF . $body;
    }

    /**
     * Склеивает части через границу multipart.
     *
     * @param array<int, string> $parts
     */
    private function joinParts(array $parts, string $boundary): string
    {
        $result = '';
        foreach ($parts as $part) {
            $result .= '--' . $boundary . self::CRLF . $part . self::CRLF;
        }

        return $result . '--' . $boundary . '--' . self::CRLF;
    }

    private function boundary(string $prefix): string
    {
        return '=_' . $prefix . '_' . bin2hex(random_bytes(12));
    }

    /**
     * Латинское имя файла для устаревшего параметра name.
     */
    private function asciiName(string $name): string
    {
        $ascii = preg_replace('/[^\x20-\x7E]/', '_', $name) ?? 'file';

        return str_replace('"', '', $ascii);
    }

    /**
     * Message-ID вида <uuid@домен>.
     */
    private function generateMessageId(Message $message): string
    {
        $domain = (string) Config::get('app.hostname', 'localhost');

        if ($domain === '' || $domain === 'localhost') {
            $sender = $message->sender();
            if ($sender !== '' && str_contains($sender, '@')) {
                $domain = substr(strrchr($sender, '@') ?: '@localhost', 1);
            }
        }

        return '<' . Str::uuid() . '@' . ($domain !== '' ? $domain : 'localhost') . '>';
    }
}
