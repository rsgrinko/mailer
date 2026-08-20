<?php

declare(strict_types=1);

namespace Mailer\Bounce;

use Mailer\Support\Config;
use Mailer\Support\Logger;
use Mailer\Support\MailerException;

/**
 * Клиент POP3 на обычных сокетах — им сборщик забирает отказы из почтового ящика.
 *
 * POP3, а не IMAP: ящик отказов нужен только чтобы прочитать письма и удалить их,
 * а протокол проще ровно на столько же. Поддерживает ssl (порт 995), starttls
 * и открытое соединение.
 */
final class Pop3Client
{
    private const CRLF = "\r\n";

    private string $host;
    private int $port;
    /** ssl | tls | none */
    private string $encryption;
    private string $username;
    private string $password;
    private int $timeout;
    private bool $verifyPeer;

    /** @var resource|null */
    private $socket = null;

    private Logger $logger;
    private bool $debug;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(array $options)
    {
        $this->host       = (string) ($options['host'] ?? '');
        $this->port       = (int) ($options['port'] ?? 995);
        $this->encryption = strtolower((string) ($options['encryption'] ?? 'ssl'));
        $this->username   = (string) ($options['username'] ?? '');
        $this->password   = (string) ($options['password'] ?? '');
        $this->timeout    = (int) ($options['timeout'] ?? Config::get('smtp.timeout', 30));
        $this->verifyPeer = (bool) ($options['verify_peer'] ?? Config::get('smtp.verify_peer', true));

        $this->logger = new Logger('bounce');
        $this->debug  = (bool) Config::get('log.smtp_conversation', false);
    }

    /**
     * Подключиться и войти в ящик.
     */
    public function connect(): void
    {
        if ($this->socket !== null) {
            return;
        }

        if ($this->host === '' || $this->username === '') {
            throw new MailerException('Ящик отказов не настроен: нужны BOUNCE_HOST и BOUNCE_USERNAME');
        }

        $scheme  = $this->encryption === 'ssl' ? 'ssl://' : 'tcp://';
        $context = stream_context_create([
            'ssl' => [
                'verify_peer'       => $this->verifyPeer,
                'verify_peer_name'  => $this->verifyPeer,
                'allow_self_signed' => !$this->verifyPeer,
                'SNI_enabled'       => true,
                'peer_name'         => $this->host,
            ],
        ]);

        $errorCode    = 0;
        $errorMessage = '';

        $socket = @stream_socket_client(
            $scheme . $this->host . ':' . $this->port,
            $errorCode,
            $errorMessage,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if ($socket === false) {
            throw new MailerException(
                'Не удалось подключиться к ящику отказов ' . $this->host . ':' . $this->port
                . ' — ' . $errorMessage . ' (' . $errorCode . ')'
            );
        }

        $this->socket = $socket;
        stream_set_timeout($this->socket, $this->timeout);

        // Приветствие сервера
        $this->response();

        if ($this->encryption === 'tls') {
            $this->command('STLS');

            if (!@stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                $this->close();

                throw new MailerException('Не удалось включить шифрование TLS для ' . $this->host);
            }
        }

        $this->command('USER ' . $this->username);
        $this->command('PASS ' . $this->password, true);
    }

    /**
     * Номера писем в ящике.
     *
     * @return array<int, int>
     */
    public function messages(): array
    {
        $this->connect();

        $this->command('LIST');

        $numbers = [];
        foreach ($this->multiline() as $line) {
            $parts = explode(' ', trim($line));
            if ($parts[0] !== '' && ctype_digit($parts[0])) {
                $numbers[] = (int) $parts[0];
            }
        }

        return $numbers;
    }

    /**
     * Письмо целиком.
     */
    public function fetch(int $number): string
    {
        $this->connect();

        $this->command('RETR ' . $number);

        return implode(self::CRLF, $this->multiline());
    }

    /**
     * Пометить письмо на удаление. Реально оно исчезнет после QUIT.
     */
    public function delete(int $number): void
    {
        $this->connect();

        $this->command('DELE ' . $number);
    }

    /**
     * Попрощаться: сервер применит удаления и закроет соединение.
     */
    public function quit(): void
    {
        if ($this->socket === null) {
            return;
        }

        try {
            $this->command('QUIT');
        } catch (MailerException $e) {
            // Прощание не удалось — соединение всё равно закрываем
            $this->logger->warning('POP3: сервер не ответил на QUIT', ['error' => $e->getMessage()]);
        }

        $this->close();
    }

    public function close(): void
    {
        if ($this->socket !== null) {
            @fclose($this->socket);
            $this->socket = null;
        }
    }

    /**
     * Команда с проверкой ответа. Пароль в лог не пишем.
     */
    private function command(string $command, bool $secret = false): string
    {
        $this->write($command);

        if ($this->debug) {
            $this->logger->debug('>>> ' . ($secret ? 'PASS ***скрыто***' : $command));
        }

        return $this->response();
    }

    /**
     * Однострочный ответ. Всё, что не +OK, — ошибка.
     */
    private function response(): string
    {
        $line = $this->readLine();

        if ($this->debug) {
            $this->logger->debug('<<< ' . $line);
        }

        if (!str_starts_with($line, '+OK')) {
            throw new MailerException('Ящик отказов ответил отказом: ' . trim($line));
        }

        return $line;
    }

    /**
     * Многострочный ответ: до строки с одной точкой.
     *
     * @return array<int, string>
     */
    private function multiline(): array
    {
        $lines = [];

        while (true) {
            $line = rtrim($this->readLine(), "\r\n");

            if ($line === '.') {
                break;
            }

            // Точка в начале строки удваивается при передаче — возвращаем как было
            if (str_starts_with($line, '..')) {
                $line = substr($line, 1);
            }

            $lines[] = $line;
        }

        return $lines;
    }

    private function readLine(): string
    {
        if ($this->socket === null) {
            throw new MailerException('Соединение с ящиком отказов закрыто');
        }

        $line = fgets($this->socket, 8192);

        if ($line === false) {
            $info = stream_get_meta_data($this->socket);
            $this->close();

            throw new MailerException(
                $info['timed_out'] ?? false
                    ? 'Ящик отказов не ответил за ' . $this->timeout . ' с'
                    : 'Ящик отказов неожиданно закрыл соединение'
            );
        }

        return $line;
    }

    private function write(string $command): void
    {
        if ($this->socket === null) {
            throw new MailerException('Соединение с ящиком отказов закрыто');
        }

        if (@fwrite($this->socket, $command . self::CRLF) === false) {
            $this->close();

            throw new MailerException('Не удалось отправить команду ящику отказов');
        }
    }
}
