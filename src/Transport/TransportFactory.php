<?php

declare(strict_types=1);

namespace Mailer\Transport;

use Mailer\Repository\TransportRepository;
use Mailer\Support\MailerException;

/**
 * Создаёт транспорт по записи из базы. Составные транспорты (цепочка и очередь)
 * собирает из других транспортов по их именам.
 */
final class TransportFactory
{
    private TransportRepository $repository;

    public function __construct(?TransportRepository $repository = null)
    {
        $this->repository = $repository ?? new TransportRepository();
    }

    /**
     * Транспорт по строке из базы.
     *
     * @param array<string, mixed> $row
     * @param array<int, string> $chain Имена транспортов выше по цепочке — защита от зацикливания
     */
    public function fromRow(array $row, array $chain = []): TransportInterface
    {
        $name = (string) ($row['name'] ?? 'unknown');
        $type = (string) ($row['type'] ?? '');

        if (in_array($name, $chain, true)) {
            throw new MailerException('Транспорты зациклены: ' . implode(' -> ', [...$chain, $name]));
        }

        $settings = (array) ($row['settings'] ?? []);

        // Отправитель по умолчанию хранится отдельными колонками — кладём его в настройки
        $settings['from_email'] = $settings['from_email'] ?? ($row['from_email'] ?? null);
        $settings['from_name']  = $settings['from_name'] ?? ($row['from_name'] ?? null);

        return match ($type) {
            'smtp'     => new SmtpTransport($name, $settings),
            'sendmail' => new SendmailTransport($name, $settings),
            'log'      => new LogTransport($name, $settings),
            'null'     => new NullTransport($name, $settings),
            'failover' => new FailoverTransport($name, $settings, $this->children($settings, [...$chain, $name])),
            'roundrobin' => new RoundRobinTransport($name, $settings, $this->children($settings, [...$chain, $name])),
            default    => throw new MailerException('Неизвестный тип транспорта: ' . $type),
        };
    }

    /**
     * Транспорт по имени.
     */
    public function byName(string $name): TransportInterface
    {
        $row = $this->repository->findByName($name);
        if ($row === null) {
            throw new MailerException('Транспорт «' . $name . '» не найден');
        }

        return $this->fromRow($row);
    }

    /**
     * Транспорт по id.
     */
    public function byId(int $id): TransportInterface
    {
        $row = $this->repository->find($id);
        if ($row === null) {
            throw new MailerException('Транспорт не найден: id=' . $id);
        }

        return $this->fromRow($row);
    }

    /**
     * Транспорт по умолчанию.
     */
    public function default(): TransportInterface
    {
        $row = $this->repository->default();
        if ($row === null) {
            throw new MailerException('В базе нет ни одного активного транспорта. Добавьте его командой transport:add или в панели.');
        }

        return $this->fromRow($row);
    }

    /**
     * Выбирает, чем отправлять письмо: сначала транспорт письма, затем транспорт проекта,
     * затем транспорт по умолчанию.
     *
     * @param array<string, mixed>|null $project
     * @return array{transport: TransportInterface, row: array<string, mixed>}
     */
    public function resolve(?int $transportId, ?array $project = null): array
    {
        $row = null;

        if ($transportId !== null) {
            $row = $this->repository->find($transportId);
        }

        if ($row === null && $project !== null && ($project['transport_id'] ?? null) !== null) {
            $row = $this->repository->find((int) $project['transport_id']);
        }

        if ($row === null) {
            $row = $this->repository->default();
        }

        if ($row === null) {
            throw new MailerException('Не удалось выбрать транспорт: в базе нет активных транспортов');
        }

        if ((int) ($row['active'] ?? 1) !== 1) {
            throw new MailerException('Транспорт «' . $row['name'] . '» выключен');
        }

        return ['transport' => $this->fromRow($row), 'row' => $row];
    }

    /**
     * Вложенные транспорты для цепочки и round-robin.
     *
     * @param array<string, mixed> $settings
     * @param array<int, string> $chain
     * @return array<int, TransportInterface>
     */
    private function children(array $settings, array $chain): array
    {
        $names    = (array) ($settings['transports'] ?? []);
        $children = [];

        foreach ($names as $name) {
            $row = $this->repository->findByName((string) $name);
            if ($row === null) {
                throw new MailerException('В составном транспорте указан несуществующий транспорт: ' . $name);
            }
            if ((int) $row['active'] !== 1) {
                continue;
            }

            $children[] = $this->fromRow($row, $chain);
        }

        return $children;
    }
}
