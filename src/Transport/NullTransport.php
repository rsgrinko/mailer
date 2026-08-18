<?php

declare(strict_types=1);

namespace Mailer\Transport;

use Mailer\Message\Message;

/**
 * Заглушка: письмо считается отправленным, но никуда не уходит.
 * Нужна для тестов и для «выключения» отправки без правки кода приложений.
 */
final class NullTransport extends BaseTransport
{
    public function type(): string
    {
        return 'null';
    }

    public function send(Message $message): string
    {
        // Всё равно собираем письмо — так тесты проверяют, что оно вообще собирается
        $mime = $this->render($message);

        return 'Письмо отброшено (транспорт-заглушка), размер ' . strlen($mime) . ' байт';
    }
}
