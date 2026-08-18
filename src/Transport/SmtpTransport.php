<?php

declare(strict_types=1);

namespace Mailer\Transport;

use Mailer\Message\Message;

/**
 * Отправка через внешний SMTP-сервер: Яндекс, Mail.ru, Gmail, корпоративный релей.
 *
 * Настройки транспорта:
 *   host, port, encryption (ssl|tls|none), username, password,
 *   auth_mode (auto|login|plain|cram-md5), timeout, verify_peer,
 *   from_email, from_name, dkim
 */
final class SmtpTransport extends BaseTransport
{
    public function type(): string
    {
        return 'smtp';
    }

    public function send(Message $message): string
    {
        $mime       = $this->render($message);
        $recipients = $message->recipients();
        $sender     = $message->sender();

        if ($sender === '') {
            throw TransportException::permanent('Не указан отправитель письма');
        }

        $client = $this->client();

        try {
            $response = $client->send($sender, $recipients, $mime);
            $client->quit();

            return $response;
        } catch (TransportException $e) {
            $client->close();
            throw $e;
        }
    }

    public function test(): string
    {
        $client = $this->client();

        try {
            return $client->ping();
        } finally {
            $client->close();
        }
    }

    private function client(): SmtpClient
    {
        return new SmtpClient([
            'host'         => (string) $this->setting('host', ''),
            'port'         => (int) $this->setting('port', 465),
            'encryption'   => (string) $this->setting('encryption', 'ssl'),
            'username'     => (string) $this->setting('username', ''),
            'password'     => (string) $this->setting('password', ''),
            'auth_mode'    => (string) $this->setting('auth_mode', 'auto'),
            'timeout'      => (int) $this->setting('timeout', 30),
            'verify_peer'  => (bool) $this->setting('verify_peer', true),
            'local_domain' => (string) $this->setting('local_domain', ''),
        ]);
    }
}
