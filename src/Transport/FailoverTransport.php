<?php

declare(strict_types=1);

namespace Mailer\Transport;

use Mailer\Message\Message;
use Mailer\Support\Logger;

/**
 * Цепочка транспортов: пробуем первый, не вышло — второй и так далее.
 * Удобно, когда основной SMTP иногда недоступен или упирается в лимит.
 *
 * Настройки: transports — список имён транспортов из базы.
 */
final class FailoverTransport extends BaseTransport
{
    /** @var array<int, TransportInterface> */
    private array $children;

    private Logger $logger;

    /**
     * @param array<string, mixed> $settings
     * @param array<int, TransportInterface> $children
     */
    public function __construct(string $name, array $settings, array $children)
    {
        parent::__construct($name, $settings);

        $this->children = $children;
        $this->logger   = new Logger('transport');
    }

    public function type(): string
    {
        return 'failover';
    }

    public function send(Message $message): string
    {
        if ($this->children === []) {
            throw TransportException::permanent('В цепочке «' . $this->name . '» нет ни одного транспорта');
        }

        $lastException = null;
        $anyTemporary  = false;

        foreach ($this->children as $transport) {
            try {
                $result = $transport->send($message);

                return 'Через «' . $transport->name() . '»: ' . $result;
            } catch (TransportException $e) {
                $lastException = $e;
                $anyTemporary  = $anyTemporary || $e->isTemporary();

                $this->logger->warning('Транспорт из цепочки не смог отправить письмо', [
                    'chain'     => $this->name,
                    'transport' => $transport->name(),
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        $text = 'Ни один транспорт цепочки «' . $this->name . '» не смог отправить письмо. '
            . 'Последняя ошибка: ' . ($lastException?->getMessage() ?? 'неизвестна');

        throw $anyTemporary
            ? TransportException::temporary($text, [], $lastException)
            : TransportException::permanent($text, [], $lastException);
    }

    /**
     * Сессию держат вложенные транспорты — закрывать нужно у них.
     */
    public function close(): void
    {
        foreach ($this->children as $transport) {
            $transport->close();
        }
    }

    public function test(): string
    {
        $report = [];

        foreach ($this->children as $transport) {
            try {
                $report[] = $transport->name() . ': ' . $transport->test();
            } catch (TransportException $e) {
                $report[] = $transport->name() . ': ОШИБКА — ' . $e->getMessage();
            }
        }

        return $report === [] ? 'В цепочке нет транспортов' : implode("\n", $report);
    }
}
