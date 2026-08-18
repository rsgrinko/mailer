<?php

declare(strict_types=1);

namespace Mailer\Transport;

use Mailer\Message\Message;
use Mailer\Support\Config;

/**
 * Ничего никуда не отправляет — складывает письма в файлы .eml.
 * Удобно на разработке: письмо можно открыть любым почтовым клиентом.
 *
 * Настройки: dir (по умолчанию var/spool/sent).
 */
final class LogTransport extends BaseTransport
{
    public function type(): string
    {
        return 'log';
    }

    public function send(Message $message): string
    {
        $mime = $this->render($message);
        $dir  = (string) $this->setting('dir', Config::get('paths.spool', MAILER_ROOT . '/var/spool') . '/sent');

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw TransportException::temporary('Не удалось создать каталог для писем: ' . $dir);
        }

        $file = $dir . '/' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 8) . '.eml';

        if (@file_put_contents($file, $mime) === false) {
            throw TransportException::temporary('Не удалось записать письмо в файл: ' . $file);
        }

        return 'Письмо сохранено в файл ' . $file;
    }

    public function test(): string
    {
        $dir = (string) $this->setting('dir', Config::get('paths.spool', MAILER_ROOT . '/var/spool') . '/sent');

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw TransportException::permanent('Каталог недоступен для записи: ' . $dir);
        }

        return 'Письма будут складываться в ' . $dir;
    }
}
