<?php

declare(strict_types=1);

namespace Mailer\Dkim;

use Mailer\Message\Encoder;
use Mailer\Message\MimeParser;
use Mailer\Support\MailerException;

/**
 * DKIM-подпись письма (rsa-sha256, канонизация relaxed/relaxed).
 *
 * Нужна, когда мы отправляем письма от своего домена через sendmail — тогда почтовые
 * системы могут проверить, что письмо действительно от нас. При отправке через SMTP
 * Яндекса подпись ставит сам Яндекс, и наша не требуется.
 */
final class Signer
{
    /** Заголовки, которые подписываем по умолчанию */
    private const DEFAULT_HEADERS = ['from', 'to', 'cc', 'subject', 'date', 'message-id', 'mime-version', 'content-type'];

    private string $domain;
    private string $selector;
    private string $privateKey;

    /** @var array<int, string> */
    private array $headers;

    /**
     * @param string $privateKey Приватный ключ в PEM (или путь к файлу с ним)
     * @param array<int, string> $headers
     */
    public function __construct(string $domain, string $selector, string $privateKey, array $headers = [])
    {
        if (is_file($privateKey)) {
            $privateKey = (string) file_get_contents($privateKey);
        }

        $this->domain     = $domain;
        $this->selector   = $selector;
        $this->privateKey = $privateKey;
        $this->headers    = $headers !== [] ? array_map('strtolower', $headers) : self::DEFAULT_HEADERS;
    }

    /**
     * Возвращает письмо с добавленным заголовком DKIM-Signature.
     */
    public function sign(string $mime): string
    {
        $mime = Encoder::normalizeLineEndings($mime);
        [$head, $body] = MimeParser::split($mime);

        $bodyHash = base64_encode(hash('sha256', $this->canonicalizeBody($body), true));

        // Подписываем только те заголовки, которые реально есть в письме
        $present = [];
        foreach ($this->headers as $name) {
            if (preg_match('/^' . preg_quote($name, '/') . ':/mi', $head . "\r\n") === 1) {
                $present[] = $name;
            }
        }

        $signatureHeader = sprintf(
            'v=1; a=rsa-sha256; q=dns/txt; c=relaxed/relaxed; s=%s; d=%s; h=%s; t=%d; bh=%s; b=',
            $this->selector,
            $this->domain,
            implode(':', $present),
            time(),
            $bodyHash
        );

        $toSign = $this->canonicalizeHeaders($head, $present)
            . 'dkim-signature:' . $this->unfold($signatureHeader);

        $key = openssl_pkey_get_private($this->privateKey);
        if ($key === false) {
            throw new MailerException('Не удалось прочитать приватный ключ DKIM');
        }

        $signature = '';
        if (!openssl_sign($toSign, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new MailerException('Не удалось подписать письмо ключом DKIM');
        }

        $header = 'DKIM-Signature: ' . $signatureHeader . base64_encode($signature);

        return $this->wrap($header) . "\r\n" . $mime;
    }

    /**
     * Канонизация тела: убираем хвостовые пробелы и лишние пустые строки в конце.
     */
    private function canonicalizeBody(string $body): string
    {
        $body = preg_replace("/[ \t]+\r\n/", "\r\n", $body) ?? $body;
        $body = preg_replace("/[ \t]+/", ' ', $body) ?? $body;
        $body = rtrim($body, "\r\n");

        return $body === '' ? '' : $body . "\r\n";
    }

    /**
     * Канонизация заголовков: имя в нижний регистр, пробелы схлопнуты, переносы убраны.
     *
     * @param array<int, string> $names
     */
    private function canonicalizeHeaders(string $head, array $names): string
    {
        $result = '';

        foreach ($names as $name) {
            if (preg_match('/^' . preg_quote($name, '/') . ':(.*?)(?=\r\n[^ \t]|\z)/msi', $head, $m) !== 1) {
                continue;
            }

            $result .= $name . ':' . $this->unfold($m[1]) . "\r\n";
        }

        return $result;
    }

    /**
     * Склеивает многострочное значение в одну строку и убирает лишние пробелы.
     */
    private function unfold(string $value): string
    {
        $value = str_replace(["\r\n", "\r", "\n"], ' ', $value);
        $value = preg_replace('/[ \t]+/', ' ', $value) ?? $value;

        return trim($value);
    }

    /**
     * Разбивает длинный заголовок подписи на строки.
     *
     * Переносить можно не где угодно. Получатель перед проверкой разворачивает
     * заголовок обратно и схлопывает пробелы: перенос посреди значения превращается
     * в пробел, которого при подписи не было, и подпись перестаёт сходиться —
     * письмо приходит с провалившимся DKIM. Поэтому переносим только там, где
     * пробел и так есть, — после «;», — и внутри b=, которое при проверке очищается.
     */
    private function wrap(string $header): string
    {
        $limit    = 78;
        $position = strrpos($header, '; b=');

        if ($position === false) {
            return $header;
        }

        $lines = [];
        $line  = '';

        // Поля до подписи: собираем в строки по границам «; »
        foreach (explode('; ', substr($header, 0, $position)) as $index => $tag) {
            $piece = $index === 0 ? $tag : $tag;

            if ($line === '') {
                $line = $piece;

                continue;
            }

            if (strlen($line) + strlen($piece) + 2 > $limit) {
                $lines[] = $line . ';';
                $line    = $piece;

                continue;
            }

            $line .= '; ' . $piece;
        }

        $lines[] = $line . ';';

        // Сама подпись — одним полем, её base64 режем как удобно
        $signature = substr($header, $position + 2);

        foreach (explode("\r\n", trim(chunk_split($signature, $limit, "\r\n"))) as $chunk) {
            $lines[] = $chunk;
        }

        return implode("\r\n\t", $lines);
    }
}
