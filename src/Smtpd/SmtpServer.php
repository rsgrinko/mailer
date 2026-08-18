<?php

declare(strict_types=1);

namespace Mailer\Smtpd;

use Mailer\MailService;
use Mailer\Repository\MessageRepository;
use Mailer\Repository\ProjectRepository;
use Mailer\Support\Config;
use Mailer\Support\Logger;
use Throwable;

/**
 * Локальный SMTP-сервер. Нужен приложениям, которые не умеют ходить в наш HTTP API,
 * зато умеют отправлять почту по SMTP: в их настройках указывается 127.0.0.1:2525,
 * а дальше письмо попадает в нашу очередь.
 *
 * Запуск: php bin/mailer smtpd
 *
 * Сервер намеренно простой: слушает только локальный адрес, без TLS.
 * Наружу его выставлять не нужно.
 */
final class SmtpServer
{
    private const CRLF = "\r\n";

    private string $host;
    private int $port;
    private string $hostname;
    private int $maxSize;
    private string $authUser;
    private string $authPassword;

    private MailService $service;
    private ProjectRepository $projects;
    private Logger $logger;

    /** @var callable(string): void */
    private $output;

    private bool $stopping = false;

    public function __construct(?string $host = null, ?int $port = null, ?callable $output = null)
    {
        $this->host         = $host ?? (string) Config::get('smtpd.host', '127.0.0.1');
        $this->port         = $port ?? (int) Config::get('smtpd.port', 2525);
        $this->hostname     = (string) Config::get('smtpd.hostname', 'mailer.local');
        $this->maxSize      = (int) Config::get('smtpd.max_size', 25 * 1024 * 1024);
        $this->authUser     = (string) Config::get('smtpd.auth_user', '');
        $this->authPassword = (string) Config::get('smtpd.auth_password', '');

        $this->service  = new MailService();
        $this->projects = new ProjectRepository();
        $this->logger   = new Logger('smtpd');
        $this->output   = $output ?? static function (string $line): void {
            echo $line . PHP_EOL;
        };
    }

    /**
     * Основной цикл: принимаем соединения одно за другим.
     */
    public function run(): void
    {
        $address = 'tcp://' . $this->host . ':' . $this->port;
        $errorNo = 0;
        $error   = '';

        $server = @stream_socket_server($address, $errorNo, $error);
        if ($server === false) {
            $this->say('Не удалось занять адрес ' . $address . ': ' . $error);

            return;
        }

        $this->say('SMTP-релей слушает ' . $address);
        $this->say('Пропишите этот адрес в настройках почты своего приложения. Остановка — Ctrl+C.');
        $this->logger->info('SMTP-релей запущен', ['address' => $address]);

        $this->listenForSignals();

        while (!$this->stopping) {
            $client = @stream_socket_accept($server, 5, $peer);

            if ($client === false) {
                if (function_exists('pcntl_signal_dispatch')) {
                    pcntl_signal_dispatch();
                }
                continue;
            }

            try {
                $this->handleClient($client, (string) $peer);
            } catch (Throwable $e) {
                $this->logger->error('Ошибка при обработке соединения', ['error' => $e->getMessage()]);
            } finally {
                @fclose($client);
            }
        }

        fclose($server);
        $this->say('SMTP-релей остановлен');
    }

    /**
     * Диалог с одним клиентом.
     *
     * @param resource $client
     */
    private function handleClient($client, string $peer): void
    {
        stream_set_timeout($client, 60);

        $this->send($client, '220 ' . $this->hostname . ' готов принимать почту');

        $from         = '';
        $recipients   = [];
        $authRequired = $this->authUser !== '';
        $authorized   = !$authRequired;

        while (($line = fgets($client, 4096)) !== false) {
            $line    = rtrim($line, "\r\n");
            $command = strtoupper(strtok($line, ' ') ?: '');

            switch ($command) {
                case 'EHLO':
                    $this->send($client, '250-' . $this->hostname);
                    $this->send($client, '250-SIZE ' . $this->maxSize);
                    $this->send($client, '250-8BITMIME');
                    if ($authRequired) {
                        $this->send($client, '250-AUTH LOGIN PLAIN');
                    }
                    $this->send($client, '250 HELP');
                    break;

                case 'HELO':
                    $this->send($client, '250 ' . $this->hostname);
                    break;

                case 'AUTH':
                    $authorized = $this->handleAuth($client, $line);
                    break;

                case 'MAIL':
                    if (!$authorized) {
                        $this->send($client, '530 Требуется авторизация');
                        break;
                    }
                    $from       = $this->extractAddress($line);
                    $recipients = [];
                    $this->send($client, '250 OK');
                    break;

                case 'RCPT':
                    if (!$authorized) {
                        $this->send($client, '530 Требуется авторизация');
                        break;
                    }
                    $address = $this->extractAddress($line);
                    if ($address === '') {
                        $this->send($client, '501 Не понял адрес получателя');
                        break;
                    }
                    $recipients[] = $address;
                    $this->send($client, '250 OK');
                    break;

                case 'DATA':
                    if ($recipients === []) {
                        $this->send($client, '503 Сначала нужно указать получателей');
                        break;
                    }

                    $this->send($client, '354 Отправляйте письмо, в конце строка с одной точкой');
                    $data = $this->readData($client);

                    if ($data === null) {
                        $this->send($client, '552 Письмо слишком большое');
                        break;
                    }

                    $result = $this->accept($from, $recipients, $data, $peer);
                    $this->send($client, $result);

                    $from       = '';
                    $recipients = [];
                    break;

                case 'RSET':
                    $from       = '';
                    $recipients = [];
                    $this->send($client, '250 OK');
                    break;

                case 'NOOP':
                    $this->send($client, '250 OK');
                    break;

                case 'QUIT':
                    $this->send($client, '221 До связи');

                    return;

                case 'VRFY':
                    $this->send($client, '252 Проверить не могу, но письмо приму');
                    break;

                default:
                    $this->send($client, '500 Команда не поддерживается');
            }
        }
    }

    /**
     * Принимает письмо в очередь и возвращает ответ SMTP.
     *
     * @param array<int, string> $recipients
     */
    private function accept(string $from, array $recipients, string $data, string $peer): string
    {
        try {
            $project = $this->projects->findOrCreate(
                (string) Config::get('smtpd.project', 'local-relay'),
                'Письма из локального SMTP-релея'
            );

            $result = $this->service->accept(
                [
                    'raw'           => $data,
                    'envelope_from' => $from,
                    'envelope_to'   => $recipients,
                    'meta'          => ['peer' => $peer],
                ],
                $project,
                MessageRepository::SOURCE_SMTPD
            );

            $this->say(date('H:i:s') . '  принято письмо ' . $result['uuid'] . ' для ' . implode(', ', $recipients));

            return '250 OK: письмо принято, идентификатор ' . $result['uuid'];
        } catch (Throwable $e) {
            $this->logger->error('Не удалось принять письмо от релея', ['error' => $e->getMessage(), 'peer' => $peer]);

            return '451 Не удалось принять письмо: ' . str_replace(["\r", "\n"], ' ', $e->getMessage());
        }
    }

    /**
     * Читает тело письма до строки с одной точкой.
     *
     * @param resource $client
     */
    private function readData($client): ?string
    {
        $data = '';

        while (($line = fgets($client, 8192)) !== false) {
            if (rtrim($line, "\r\n") === '.') {
                return $data;
            }

            // Точка в начале строки экранируется отправителем — возвращаем как было
            if (str_starts_with($line, '..')) {
                $line = substr($line, 1);
            }

            $data .= $line;

            if (strlen($data) > $this->maxSize) {
                return null;
            }
        }

        return $data;
    }

    /**
     * Разбор AUTH LOGIN и AUTH PLAIN.
     *
     * @param resource $client
     */
    private function handleAuth($client, string $line): bool
    {
        $parts  = explode(' ', $line);
        $method = strtoupper($parts[1] ?? '');

        if ($method === 'PLAIN') {
            $token = $parts[2] ?? '';
            if ($token === '') {
                $this->send($client, '334 ');
                $token = trim((string) fgets($client, 1024));
            }

            $decoded = base64_decode($token, true);
            $pieces  = $decoded === false ? [] : explode("\0", $decoded);
            $ok      = ($pieces[1] ?? '') === $this->authUser && ($pieces[2] ?? '') === $this->authPassword;

            $this->send($client, $ok ? '235 Авторизация пройдена' : '535 Неверный логин или пароль');

            return $ok;
        }

        if ($method === 'LOGIN') {
            $this->send($client, '334 ' . base64_encode('Username:'));
            $user = base64_decode(trim((string) fgets($client, 1024)), true) ?: '';

            $this->send($client, '334 ' . base64_encode('Password:'));
            $password = base64_decode(trim((string) fgets($client, 1024)), true) ?: '';

            $ok = $user === $this->authUser && $password === $this->authPassword;
            $this->send($client, $ok ? '235 Авторизация пройдена' : '535 Неверный логин или пароль');

            return $ok;
        }

        $this->send($client, '504 Такой способ авторизации не поддерживается');

        return false;
    }

    /**
     * Достаёт адрес из MAIL FROM:<...> и RCPT TO:<...>.
     */
    private function extractAddress(string $line): string
    {
        if (preg_match('/<([^>]*)>/', $line, $m) === 1) {
            return trim($m[1]);
        }

        // Некоторые клиенты пишут адрес без угловых скобок
        $parts = explode(':', $line, 2);

        return isset($parts[1]) ? trim($parts[1]) : '';
    }

    /**
     * @param resource $client
     */
    private function send($client, string $line): void
    {
        @fwrite($client, $line . self::CRLF);
    }

    private function listenForSignals(): void
    {
        if (!function_exists('pcntl_signal')) {
            return;
        }

        $handler = function (): void {
            $this->stopping = true;
        };

        pcntl_signal(SIGTERM, $handler);
        pcntl_signal(SIGINT, $handler);
    }

    private function say(string $line): void
    {
        ($this->output)($line);
    }
}
