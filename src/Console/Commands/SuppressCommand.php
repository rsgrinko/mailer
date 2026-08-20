<?php

declare(strict_types=1);

namespace Mailer\Console\Commands;

use Mailer\Console\Command;
use Mailer\Repository\ProjectRepository;
use Mailer\Repository\SuppressionRepository;
use Mailer\Support\Validator;

/**
 * закрыть адрес: письма ему больше не уйдут.
 */
final class SuppressCommand extends Command
{
    public function name(): string
    {
        return 'suppress:add';
    }

    public function description(): string
    {
        return 'закрыть адрес стоп-листом';
    }

    public function usage(): string
    {
        return 'suppress:add <email> [--reason=manual|bounce|complaint|unsubscribe] [--project=имя] [--note=текст]';
    }

    public function run(): int
    {
        $email = $this->arg(0);

        if (!Validator::isEmail($email)) {
            $this->line('Нужен адрес: php bin/mailer ' . $this->usage());

            return 1;
        }

        $projectId = 0;
        $project   = (string) $this->option('project', '');
        if ($project !== '') {
            $row = (new ProjectRepository())->findByName($project);

            if ($row === null) {
                $this->line('Проект не найден: ' . $project);

                return 1;
            }

            $projectId = (int) $row['id'];
        }

        $reason = (string) $this->option('reason', SuppressionRepository::MANUAL);
        if (!in_array($reason, SuppressionRepository::REASONS, true)) {
            $this->line('Причина бывает такой: ' . implode(', ', SuppressionRepository::REASONS));

            return 1;
        }

        (new SuppressionRepository())->block($email, $reason, 'cli', [
            'project_id' => $projectId,
            'note'       => (string) $this->option('note', ''),
        ]);

        $this->line('Адрес закрыт: ' . SuppressionRepository::normalize($email)
            . ($projectId > 0 ? ' (только для проекта «' . $project . '»)' : ' (для всех проектов)'));

        return 0;
    }
}
