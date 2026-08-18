<?php

declare(strict_types=1);

namespace Mailer\Transport;

use Mailer\Message\Message;
use Mailer\Repository\SettingRepository;

/**
 * По очереди раскидывает письма между транспортами — так проще жить с суточными
 * лимитами почтовых сервисов. Если очередной транспорт отказал, пробуем следующий.
 *
 * Настройки: transports — список имён транспортов из базы.
 */
final class RoundRobinTransport extends BaseTransport
{
    /** @var array<int, TransportInterface> */
    private array $children;

    private SettingRepository $settingsRepository;

    /**
     * @param array<string, mixed> $settings
     * @param array<int, TransportInterface> $children
     */
    public function __construct(string $name, array $settings, array $children, ?SettingRepository $settingsRepository = null)
    {
        parent::__construct($name, $settings);

        $this->children           = $children;
        $this->settingsRepository = $settingsRepository ?? new SettingRepository();
    }

    public function type(): string
    {
        return 'roundrobin';
    }

    public function send(Message $message): string
    {
        $count = count($this->children);
        if ($count === 0) {
            throw TransportException::permanent('В наборе «' . $this->name . '» нет ни одного транспорта');
        }

        // Позицию храним в базе, чтобы очередь не сбивалась между процессами воркера
        $key   = 'roundrobin:' . $this->name;
        $start = (int) $this->settingsRepository->get($key, '0');

        $lastException = null;
        $anyTemporary  = false;

        for ($i = 0; $i < $count; $i++) {
            $index     = ($start + $i) % $count;
            $transport = $this->children[$index];

            try {
                $result = $transport->send($message);
                $this->settingsRepository->set($key, (string) (($index + 1) % $count));

                return 'Через «' . $transport->name() . '»: ' . $result;
            } catch (TransportException $e) {
                $lastException = $e;
                $anyTemporary  = $anyTemporary || $e->isTemporary();
            }
        }

        $this->settingsRepository->set($key, (string) (($start + 1) % $count));

        $text = 'Ни один транспорт набора «' . $this->name . '» не смог отправить письмо. '
            . 'Последняя ошибка: ' . ($lastException?->getMessage() ?? 'неизвестна');

        throw $anyTemporary
            ? TransportException::temporary($text, [], $lastException)
            : TransportException::permanent($text, [], $lastException);
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

        return $report === [] ? 'В наборе нет транспортов' : implode("\n", $report);
    }
}
