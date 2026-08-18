<?php

declare(strict_types=1);

namespace Mailer\Support;

/**
 * Пишет логи в var/log/mailer-ГГГГ-ММ-ДД.log.
 * Формат строки: [дата] УРОВЕНЬ [канал] сообщение {контекст}
 */
final class Logger
{
    public const DEBUG   = 'debug';
    public const INFO    = 'info';
    public const WARNING = 'warning';
    public const ERROR   = 'error';

    /** Уровни по возрастанию важности — нужно, чтобы отсекать лишнее */
    private const WEIGHTS = [
        self::DEBUG   => 10,
        self::INFO    => 20,
        self::WARNING => 30,
        self::ERROR   => 40,
    ];

    private string $channel;
    private string $dir;
    private string $minLevel;

    public function __construct(string $channel = 'app', ?string $dir = null, ?string $minLevel = null)
    {
        $this->channel  = $channel;
        $this->dir      = $dir ?? (string) Config::get('paths.log', MAILER_ROOT . '/var/log');
        $this->minLevel = $minLevel ?? (string) Config::get('log.level', self::INFO);
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log(self::DEBUG, $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log(self::INFO, $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log(self::WARNING, $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log(self::ERROR, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function log(string $level, string $message, array $context = []): void
    {
        if ((self::WEIGHTS[$level] ?? 0) < (self::WEIGHTS[$this->minLevel] ?? 20)) {
            return;
        }

        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0775, true);
        }

        $line = sprintf(
            "[%s] %s [%s] %s%s\n",
            date('Y-m-d H:i:s'),
            strtoupper($level),
            $this->channel,
            $this->oneLine($message),
            $context === [] ? '' : ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        @file_put_contents($this->dir . '/mailer-' . date('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Схлопывает переносы строк, чтобы одна запись занимала одну строку.
     */
    private function oneLine(string $text): string
    {
        return trim(str_replace(["\r\n", "\r", "\n"], ' | ', $text));
    }

    /**
     * Список файлов логов, свежие сверху.
     *
     * @return array<int, array{name: string, path: string, size: int, mtime: int}>
     */
    public function files(): array
    {
        if (!is_dir($this->dir)) {
            return [];
        }

        $result = [];
        foreach ((array) glob($this->dir . '/mailer-*.log') as $path) {
            if (!is_string($path)) {
                continue;
            }
            $result[] = [
                'name'  => basename($path),
                'path'  => $path,
                'size'  => (int) filesize($path),
                'mtime' => (int) filemtime($path),
            ];
        }

        usort($result, static fn (array $a, array $b): int => $b['mtime'] <=> $a['mtime']);

        return $result;
    }

    /**
     * Последние $lines строк файла лога (для веб-панели).
     */
    public function tail(string $fileName, int $lines = 300): string
    {
        $path = $this->dir . '/' . basename($fileName);
        if (!is_file($path)) {
            return '';
        }

        $content = (string) file_get_contents($path);
        $all     = explode("\n", trim($content));

        return implode("\n", array_slice($all, -$lines));
    }
}
