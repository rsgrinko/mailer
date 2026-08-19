<?php

declare(strict_types=1);

namespace Mailer\Console\Commands;

use Mailer\Console\Command;
use Mailer\Repository\TemplateRepository;
use Mailer\Support\Str;

/**
 * список шаблонов.
 */
final class TemplateListCommand extends Command
{
    public function name(): string
    {
        return 'template:list';
    }

    public function description(): string
    {
        return 'список шаблонов';
    }

    public function usage(): string
    {
        return 'template:list';
    }

    public function run(): int
    {

        $templates = (new TemplateRepository())->all();

        if ($templates === []) {
            $this->line('Шаблонов нет.');

            return 0;
        }

        foreach ($templates as $template) {
            $this->line($this->pad((string) $template['id'], 5) . $this->pad((string) $template['name'], 24)
                . Str::limit((string) ($template['subject'] ?? ''), 50));
        }

        return 0;
    
    }
}
