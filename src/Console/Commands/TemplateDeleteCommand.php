<?php

declare(strict_types=1);

namespace Mailer\Console\Commands;

use Mailer\Console\Command;
use Mailer\Repository\TemplateRepository;

/**
 * удалить шаблон.
 */
final class TemplateDeleteCommand extends Command
{
    public function name(): string
    {
        return 'template:delete';
    }

    public function description(): string
    {
        return 'удалить шаблон';
    }

    public function usage(): string
    {
        return 'template:delete <имя>';
    }

    public function run(): int
    {

        $name       = $this->args[0] ?? '';
        $repository = new TemplateRepository();
        $template   = $repository->findByName($name);

        if ($template === null) {
            $this->line('Шаблон «' . $name . '» не найден');

            return 1;
        }

        $repository->delete((int) $template['id']);
        $this->line('Шаблон «' . $name . '» удалён.');

        return 0;
    
    }
}
