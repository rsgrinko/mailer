<?php

declare(strict_types=1);

namespace Mailer\Transport;

use Mailer\Message\Encoder;
use Mailer\Support\Config;
use Mailer\Support\Logger;

/**
 * Клиент SMTP на обычных сокетах: подключение, TLS, авторизация и отправка письма.
 * Написан с нуля, потому что внешние библиотеки в проекте не используются.
 *
 * Поддерживает ssl (порт 465), starttls (порт 587) и открытое соединение,
 * а также авторизацию LOGIN, PLAIN и CRAM-MD5.
 */
final class SmtpClient
{
    private const CRLF = Encoder::CRLF;

    private string $host;
    private int $port;
    /** ssl | tls | none */
    private string $encryption;
    private string $username;
    private string $password;
    private string $authMode;
    private int $timeout;
    private bool $verifyPeer;
    private string $localDomain;

    /** @var resource|null */
    private $socket = null;

    /** @var array<int, string> Возможности сервера из ответа на EHLO */
    private array $capabilities = [];

    private Logger $logger;
    private bool $debug;

    /** @var array<int, string> Последний диалог с сервером — показываем в панели при ошибке */
    private array $conversation = [];

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(array $options)
    {
        $this->host        = (string) ($options['host'] ?? '');
        $this->port        = (int) ($options['port'] ?? 25);
        $this->encryption  = strtolower((string) ($options['encryption'] ?? 'none'));
        $this->username    = (string) ($options['username'] ?? '');
        $this->password    = (string) ($options['password'] ?? '');
        $this->authMode    = strtolower((string) ($options['auth_mode'] ?? 'auto'));
        $this->timeout     = (int) ($options['timeout'] ?? Config::get('smtp.timeout', 30));
        $this->verifyPeer  = (bool) ($options['verify_peer'] ?? Config::get('smtp.verify_peer', true));
        $this->localDomain = (string) ($options['local_domain'] ?? Config::get('smtp.local_domain', ''));

        if ($this->localDomain === '') {
            $this->localDomain = gethostname() ?: 'localhost';
        }

        $this->logger = new Logger('smtp');
        $this->debug  = (bool) Config::get('log.smtp_conversation', false);
    }

    /**
     * Подключиться, поздороваться, при необходимости поднять TLS и авторизоваться.
     */
    public function connect(): void
    {
        if ($this->socket !== null) {
            return;
        }

        $this->conversation = [];

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
            throw TransportException::temporary(
                sprintf('Не удалось подключиться к %s:%d — %s', $this->host, $this->port, $errorMessage !== '' ? $errorMessage : 'нет ответа'),
                ['host' => $this->host, 'port' => $this->port, 'code' => $errorCode]
            );
        }

        $this->socket = $socket;
        stream_set_timeout($this->socket, $this->timeout);

        // Приветствие сервера
        $this->expect([220], 'приветствие сервера');

        $this->ehlo();

        if ($this->encryption === 'tls' || $this->encryption === 'starttls') {
            $this->startTls();
        }

        if ($this->username !== '') {
            $this->authenticate();
        }
    }

    /**
     * Отправляет одно письмо. Возвращает ответ сервера на завершение DATA.
     *
     * @param array<int, string> $recipients
     */
    public function send(string $from, array $recipients, string $data): string
    {
        if ($recipients === []) {
            throw TransportException::permanent('У письма нет получателей');
        }

        $this->connect();

        $this->command('MAIL FROM:<' . $from . '>', [250], 'MAIL FROM');

        foreach ($recipients as $recipient) {
            $this->command('RCPT TO:<' . $recipient . '>', [250, 251], 'RCPT TO ' . $recipient, false, ['recipient' => $recipient]);
        }

        $this->command('DATA', [354], 'DATA');

        $this->write(Encoder::dotStuff($data) . self::CRLF . '.' . self::CRLF);

        [$code, $lines] = $this->read();
        if ($code !== 250) {
            $this->fail($code, $lines, 'завершение DATA');
        }

        return trim(implode(' ', $lines));
    }

    /**
     * Вежливо попрощаться и закрыть соединение.
     */
    public function quit(): void
    {
        if ($this->socket === null) {
            return;
        }

        try {
            $this->write('QUIT' . self::CRLF);
            $this->read();
        } catch (\Throwable) {
            // Сервер мог оборвать связь первым — это не важно
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
     * Проверка соединения и авторизации без отправки письма.
     */
    public function ping(): string
    {
        $this->connect();
        $this->command('NOOP', [250], 'NOOP');
        $capabilities = implode(', ', $this->capabilities);
        $this->quit();

        return 'Подключение и авторизация прошли успешно. Возможности сервера: ' . $capabilities;
    }

    /**
     * Диалог с сервером — пригодится при разборе ошибок.
     *
     * @return array<int, string>
     */
    public function conversation(): array
    {
        return $this->conversation;
    }

    // --- Внутренняя кухня ----------------------------------------------------

    private function ehlo(): void
    {
        [$code, $lines] = $this->request('EHLO ' . $this->localDomain);

        if ($code !== 250) {
            // Старые серверы EHLO не знают — пробуем HELO
            [$code, $lines] = $this->request('HELO ' . $this->localDomain);
            if ($code !== 250) {
                $this->fail($code, $lines, 'приветствие EHLO/HELO');
            }
        }

        $this->capabilities = [];
        foreach ($lines as $index => $line) {
            if ($index === 0) {
                continue;
            }
            $this->capabilities[] = strtoupper(trim($line));
        }
    }

    private function startTls(): void
    {
        $this->command('STARTTLS', [220], 'STARTTLS');

        if ($this->socket === null) {
            throw TransportException::temporary('Соединение потеряно перед включением TLS');
        }

        $crypto = @stream_socket_enable_crypto(
            $this->socket,
            true,
            STREAM_CRYPTO_METHOD_TLS_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT
        );

        if ($crypto !== true) {
            throw TransportException::temporary('Не удалось включить шифрование TLS для ' . $this->host);
        }

        // После STARTTLS нужно поздороваться заново
        $this->ehlo();
    }

    private function authenticate(): void
    {
        $mode = $this->authMode;

        if ($mode === 'auto' || $mode === '') {
            $mode = $this->chooseAuthMode();
        }

        match ($mode) {
            'login'    => $this->authLogin(),
            'plain'    => $this->authPlain(),
            'cram-md5' => $this->authCramMd5(),
            default    => throw TransportException::permanent('Неизвестный способ авторизации SMTP: ' . $mode),
        };
    }

    /**
     * Выбираем способ авторизации по тому, что умеет сервер.
     */
    private function chooseAuthMode(): string
    {
        $auth = '';
        foreach ($this->capabilities as $capability) {
            if (str_starts_with($capability, 'AUTH')) {
                $auth = $capability;
                break;
            }
        }

        if (str_contains($auth, 'LOGIN')) {
            return 'login';
        }
        if (str_contains($auth, 'PLAIN')) {
            return 'plain';
        }
        if (str_contains($auth, 'CRAM-MD5')) {
            return 'cram-md5';
        }

        // Сервер молчит о поддержке — LOGIN понимают почти все, включая Яндекс
        return 'login';
    }

    private function authLogin(): void
    {
        $this->command('AUTH LOGIN', [334], 'запрос авторизации');
        $this->command(base64_encode($this->username), [334], 'передача логина');
        $this->command(base64_encode($this->password), [235], 'передача пароля', true);
    }

    private function authPlain(): void
    {
        $token = base64_encode("\0" . $this->username . "\0" . $this->password);
        $this->command('AUTH PLAIN ' . $token, [235], 'авторизация PLAIN', true);
    }

    private function authCramMd5(): void
    {
        [$code, $lines] = $this->request('AUTH CRAM-MD5');
        if ($code !== 334) {
            $this->fail($code, $lines, 'авторизация CRAM-MD5');
        }

        $challenge = base64_decode(trim($lines[0]), true);
        if ($challenge === false) {
            throw TransportException::permanent('Сервер прислал некорректный запрос CRAM-MD5');
        }

        $digest = hash_hmac('md5', $challenge, $this->password);
        $this->command(base64_encode($this->username . ' ' . $digest), [235], 'ответ CRAM-MD5', true);
    }

    /**
     * Отправить команду и проверить код ответа.
     *
     * @param array<int, int> $expected
     */
    private function command(string $command, array $expected, string $what, bool $secret = false, array $context = []): array
    {
        [$code, $lines] = $this->request($command, $secret);

        if (!in_array($code, $expected, true)) {
            $this->fail($code, $lines, $what, $context);
        }

        return $lines;
    }

    /**
     * @return array{0: int, 1: array<int, string>}
     */
    private function request(string $command, bool $secret = false): array
    {
        $this->log('>>> ' . ($secret ? '***скрыто***' : $command));
        $this->write($command . self::CRLF);

        return $this->read();
    }

    private function write(string $data): void
    {
        if ($this->socket === null) {
            throw TransportException::temporary('Соединение с SMTP-сервером закрыто');
        }

        $written = @fwrite($this->socket, $data);
        if ($written === false) {
            throw TransportException::temporary('Не удалось отправить данные SMTP-серверу');
        }
    }

    /**
     * Читает ответ сервера. Ответ может быть многострочным: у всех строк,
     * кроме последней, после кода стоит дефис.
     *
     * @return array{0: int, 1: array<int, string>}
     */
    private function read(): array
    {
        if ($this->socket === null) {
            throw TransportException::temporary('Соединение с SMTP-сервером закрыто');
        }

        $lines = [];
        $code  = 0;

        while (true) {
            $line = fgets($this->socket, 8192);

            if ($line === false) {
                $info = stream_get_meta_data($this->socket);
                if (!empty($info['timed_out'])) {
                    throw TransportException::temporary('SMTP-сервер не ответил за ' . $this->timeout . ' с');
                }

                throw TransportException::temporary('SMTP-сервер неожиданно закрыл соединение');
            }

            $this->log('<<< ' . rtrim($line));

            $code    = (int) substr($line, 0, 3);
            $lines[] = trim(substr($line, 4));

            // Дефис после кода означает, что будет продолжение
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }

        return [$code, $lines];
    }

    /**
     * Ожидаем определённый код сразу после подключения.
     *
     * @param array<int, int> $expected
     */
    private function expect(array $expected, string $what): void
    {
        [$code, $lines] = $this->read();

        if (!in_array($code, $expected, true)) {
            $this->fail($code, $lines, $what);
        }
    }

    /**
     * Превращает ответ сервера в исключение. 4xx — временная беда, 5xx — окончательная.
     *
     * В контекст кладём и сам ответ сервера: по нему отказ на RCPT разбирается дальше —
     * несуществующий ящик уезжает в стоп-лист, а «relay denied» не должен.
     *
     * @param array<int, string> $lines
     * @param array<string, mixed> $extra
     */
    private function fail(int $code, array $lines, string $what, array $extra = []): never
    {
        $text = trim(implode(' ', $lines));
        $message = sprintf('SMTP %s: сервер ответил %d %s', $what, $code, $text);
        $context = array_merge($extra, [
            'host'         => $this->host,
            'code'         => $code,
            'answer'       => $text,
            'conversation' => array_slice($this->conversation, -12),
        ]);

        $this->close();

        if ($code >= 400 && $code < 500) {
            throw TransportException::temporary($message, $context);
        }

        throw TransportException::permanent($message, $context);
    }

    private function log(string $line): void
    {
        $this->conversation[] = $line;

        if ($this->debug) {
            $this->logger->debug($line, ['host' => $this->host]);
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
