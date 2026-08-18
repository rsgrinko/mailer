<?php

declare(strict_types=1);

namespace Mailer\Message;

use Mailer\Support\MailerException;

/**
 * Вложение письма. Содержимое либо лежит в памяти, либо в файле (в очереди — в var/spool).
 */
final class Attachment
{
    public string $name;
    public string $contentType;
    /** Идентификатор для картинок внутри HTML: <img src="cid:logo"> */
    public string $cid;
    public bool $inline;
    /** Путь к файлу с содержимым, если оно не в памяти */
    public ?string $path;

    private ?string $content;

    public function __construct(
        string $name,
        ?string $content = null,
        string $contentType = 'application/octet-stream',
        bool $inline = false,
        string $cid = '',
        ?string $path = null
    ) {
        $this->name        = $name !== '' ? basename($name) : 'file';
        $this->content     = $content;
        $this->contentType = $contentType !== '' ? $contentType : 'application/octet-stream';
        $this->inline      = $inline;
        $this->cid         = $cid;
        $this->path        = $path;
    }

    /**
     * Вложение из файла на диске.
     */
    public static function fromPath(string $path, string $name = '', bool $inline = false, string $cid = ''): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new MailerException('Файл вложения не найден: ' . $path);
        }

        return new self(
            $name !== '' ? $name : basename($path),
            null,
            self::guessType($path),
            $inline,
            $cid,
            $path
        );
    }

    /**
     * Вложение из данных API. Содержимое приходит в base64 (поле content)
     * либо указывается путь к файлу (поле path).
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $name    = (string) ($data['name'] ?? $data['filename'] ?? '');
        $inline  = (bool) ($data['inline'] ?? (($data['disposition'] ?? '') === 'inline'));
        $cid     = (string) ($data['cid'] ?? $data['content_id'] ?? '');
        $type    = (string) ($data['content_type'] ?? $data['type'] ?? '');
        $path    = isset($data['path']) ? (string) $data['path'] : null;
        $content = null;

        if (isset($data['content']) && is_string($data['content']) && $data['content'] !== '') {
            $decoded = base64_decode($data['content'], true);
            if ($decoded === false) {
                throw new MailerException('Содержимое вложения "' . $name . '" не является корректным base64');
            }
            $content = $decoded;
        }

        if ($content === null && $path === null) {
            throw new MailerException('У вложения "' . $name . '" нет ни content, ни path');
        }

        if ($path !== null && $content === null) {
            $attachment = self::fromPath($path, $name, $inline, $cid);
            if ($type !== '') {
                $attachment->contentType = $type;
            }

            return $attachment;
        }

        if ($name === '') {
            throw new MailerException('У вложения не указано имя файла');
        }

        return new self($name, $content, $type !== '' ? $type : self::guessType($name), $inline, $cid);
    }

    /**
     * Содержимое вложения (при необходимости читает файл).
     */
    public function content(): string
    {
        if ($this->content !== null) {
            return $this->content;
        }

        if ($this->path !== null && is_file($this->path)) {
            $data = file_get_contents($this->path);
            if ($data !== false) {
                return $data;
            }
        }

        throw new MailerException('Не удалось прочитать вложение "' . $this->name . '"');
    }

    public function size(): int
    {
        if ($this->content !== null) {
            return strlen($this->content);
        }

        if ($this->path !== null && is_file($this->path)) {
            return (int) filesize($this->path);
        }

        return 0;
    }

    /**
     * Сохраняет содержимое в файл и переключает вложение на него —
     * так письма в очереди не раздувают базу.
     */
    public function moveToFile(string $path): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        if ($this->content !== null) {
            file_put_contents($path, $this->content);
        } elseif ($this->path !== null && $this->path !== $path) {
            copy($this->path, $path);
        }

        $this->path    = $path;
        $this->content = null;
    }

    /**
     * Метаданные для хранения в базе (без самого содержимого).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name'         => $this->name,
            'content_type' => $this->contentType,
            'inline'       => $this->inline,
            'cid'          => $this->cid,
            'path'         => $this->path,
            'size'         => $this->size(),
        ];
    }

    /**
     * Восстановление из данных базы.
     *
     * @param array<string, mixed> $data
     */
    public static function fromStored(array $data): self
    {
        return new self(
            (string) ($data['name'] ?? 'file'),
            null,
            (string) ($data['content_type'] ?? 'application/octet-stream'),
            (bool) ($data['inline'] ?? false),
            (string) ($data['cid'] ?? ''),
            isset($data['path']) ? (string) $data['path'] : null
        );
    }

    /**
     * Определяем тип по расширению — самых частых хватает, остальное octet-stream.
     */
    public static function guessType(string $fileName): string
    {
        $types = [
            'pdf'  => 'application/pdf',
            'zip'  => 'application/zip',
            'rar'  => 'application/vnd.rar',
            '7z'   => 'application/x-7z-compressed',
            'doc'  => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls'  => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ppt'  => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'csv'  => 'text/csv',
            'txt'  => 'text/plain',
            'html' => 'text/html',
            'xml'  => 'application/xml',
            'json' => 'application/json',
            'png'  => 'image/png',
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
            'svg'  => 'image/svg+xml',
            'ico'  => 'image/x-icon',
            'mp3'  => 'audio/mpeg',
            'mp4'  => 'video/mp4',
        ];

        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        return $types[$ext] ?? 'application/octet-stream';
    }
}
