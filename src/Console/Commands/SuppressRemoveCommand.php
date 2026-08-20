<?php

declare(strict_types=1);

namespace Mailer\Console\Commands;

use Mailer\Console\Command;
use Mailer\Repository\ProjectRepository;
use Mailer\Repository\SuppressionRepository;

/**
 * открыть адрес обратно.
 */
final class SuppressRemoveCommand extends Command
{
    public function name(): string
    {
        return 'suppress:remove';
    }

    public function description(): string
    {
        return 'открыть адрес обратно';
    }

    public function usage(): string
    {
        return 'suppress:remove <email> [--project=имя]';
    }

    public function run(): int
    {
        $email = $this->arg(0);

        if ($email === '') {
            $this->line('Нужен адрес: php bin/mailer ' . $this->usage());

            return 1;
        }

        $projectId = null;
        $project   = (string) $this->option('project', '');
        if ($project !== '') {
            $row = (new ProjectRepository())->findByName($project);

            if ($row === null) {
                $this->line('Проект не найден: ' . $project);

                return 1;
            }

            $projectId = (int) $row['id'];
        }

        $removed = (new SuppressionRepository())->unblock($email, $projectId);

        $this->line($removed === 0
            ? 'В стоп-листе такого адреса нет: ' . SuppressionRepository::normalize($email)
            : 'Адрес открыт, снято записей: ' . $removed);

        return $removed === 0 ? 1 : 0;
    }
}
