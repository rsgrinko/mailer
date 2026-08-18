<?php

declare(strict_types=1);

namespace Mailer\Transport;

use Mailer\Message\Message;

/**
 * Отправка через локальный sendmail (postfix, exim и т.п.).
 *
 * Настройки: path (по умолчанию /usr/sbin/sendmail), extra_params.
 */
final class SendmailTransport extends BaseTransport
{
    public function type(): string
    {
        return 'sendmail';
    }

    public function send(Message $message): string
    {
        $mime   = $this->render($message);
        $sender = $message->sender();
        $binary = (string) $this->setting('path', '/usr/sbin/sendmail');

        if (!$this->binaryExists($binary)) {
            throw TransportException::permanent('Не найден sendmail: ' . $binary);
        }

        // -t: получателей взять из заголовков письма, -i: не считать одиночную точку концом
        $params = (string) $this->setting('extra_params', '-t -i');
        if ($sender !== '') {
            $params .= ' -f' . escapeshellarg($sender);
        }

        $command = escapeshellcmd($binary) . ' ' . $params;

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) {
            throw TransportException::temporary('Не удалось запустить sendmail: ' . $command);
        }

        fwrite($pipes[0], $mime);
        fclose($pipes[0]);

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            $error = trim($stderr !== '' ? $stderr : $stdout);
            $message = 'sendmail завершился с кодом ' . $exitCode . ($error !== '' ? ': ' . $error : '');

            // 75 (EX_TEMPFAIL) — попросили повторить позже
            throw $exitCode === 75
                ? TransportException::temporary($message)
                : TransportException::permanent($message);
        }

        return 'Письмо передано в sendmail (' . $binary . ')';
    }

    public function test(): string
    {
        $binary = (string) $this->setting('path', '/usr/sbin/sendmail');

        if (!$this->binaryExists($binary)) {
            throw TransportException::permanent('Не найден sendmail: ' . $binary);
        }

        return 'sendmail найден: ' . $binary;
    }

    private function binaryExists(string $binary): bool
    {
        if (is_file($binary)) {
            return true;
        }

        // Может быть указано просто имя команды — поищем в PATH
        $which  = stripos(PHP_OS_FAMILY, 'Windows') === 0 ? 'where' : 'command -v';
        $output = @shell_exec($which . ' ' . escapeshellarg($binary) . ' 2>&1');

        return is_string($output) && trim($output) !== '' && !str_contains(strtolower($output), 'not found');
    }
}
