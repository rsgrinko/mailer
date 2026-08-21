<?php

declare(strict_types=1);

namespace Mailer\Transport;

use Mailer\Message\Message;
use Mailer\Support\Config;

/**
 * Отправка через внешний SMTP-сервер: Яндекс, Mail.ru, Gmail, корпоративный релей.
 *
 * Соединение между письмами не рвётся: воркер отправляет очередь пачками, а на каждое
 * подключение уходит рукопожатие TLS и авторизация — заметно дороже самого письма.
 * Сессия закрывается сама, когда воркеру нечего делать (Sender::closeTransports()),
 * после ошибки или когда в ней ушло session_limit писем: серверы не любят вечных сессий.
 *
 * Настройки транспорта:
 *   host, port, encryption (ssl|tls|none), username, password,
 *   auth_mode (auto|login|plain|cram-md5), timeout, verify_peer,
 *   keepalive, session_limit, from_email, from_name, dkim
 */
final class SmtpTransport extends BaseTransport
{
    /** Открытая сессия, если её решили не закрывать после прошлого письма */
    private ?SmtpClient $client = null;

    /** Сколько писем ушло в текущей сессии */
    private int $sent = 0;

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

        $client = $this->session();

        try {
            $response = $client->send($sender, $recipients, $mime);
            $this->sent++;

            $limit = $this->sessionLimit();

            if (!$this->keepAlive() || ($limit > 0 && $this->sent >= $limit)) {
                $this->close();
            }

            return $response;
        } catch (TransportException $e) {
            // После ошибки состояние сессии неизвестно: следующее письмо начнём с нуля
            $this->drop();

            throw $e;
        }
    }

    public function test(): string
    {
        $client = $this->newClient();

        try {
            return $client->ping();
        } finally {
            $client->close();
        }
    }

    /**
     * Закрывает сессию, если она открыта. Зовётся, когда очередь опустела.
     */
    public function close(): void
    {
        $this->client?->quit();

        $this->drop();
    }

    /**
     * Соединение для следующего письма: либо уже открытое, либо новое.
     */
    private function session(): SmtpClient
    {
        // RSET заодно проверяет, что сервер не закрыл соединение, пока мы ходили в базу
        if ($this->client !== null && $this->client->reset()) {
            return $this->client;
        }

        $this->drop();

        $this->client = $this->newClient();

        return $this->client;
    }

    /**
     * Забыть сессию, ничего не спрашивая у сервера.
     */
    private function drop(): void
    {
        $this->client?->close();

        $this->client = null;
        $this->sent   = 0;
    }

    private function keepAlive(): bool
    {
        return (bool) $this->setting('keepalive', Config::get('smtp.keepalive', true));
    }

    /**
     * Сколько писем отправляем в одной сессии. 0 — сколько угодно.
     */
    private function sessionLimit(): int
    {
        return max(0, (int) $this->setting('session_limit', Config::get('smtp.session_limit', 100)));
    }

    private function newClient(): SmtpClient
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
