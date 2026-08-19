<?php

declare(strict_types=1);

namespace Mailer\Console\Commands;

use Mailer\Console\Command;
use Mailer\RateLimit\RateLimiter;
use Mailer\Repository\ProjectRepository;
use Mailer\Support\Str;

/**
 * список проектов.
 */
final class KeyListCommand extends Command
{
    public function name(): string
    {
        return 'key:list';
    }

    public function description(): string
    {
        return 'список проектов';
    }

    public function usage(): string
    {
        return 'key:list';
    }

    public function run(): int
    {

        $projects = (new ProjectRepository())->all();
        $limiter  = new RateLimiter();

        if ($projects === []) {
            $this->line('Проектов пока нет. Создайте: php bin/mailer key:create my-site');

            return 0;
        }

        $this->line($this->pad('ID', 5) . $this->pad('Проект', 24) . $this->pad('Ключ', 24) . $this->pad('За час', 10) . $this->pad('За сутки', 10) . 'Статус');

        foreach ($projects as $project) {
            $usage = $limiter->projectUsage((int) $project['id']);

            $this->line(
                $this->pad((string) $project['id'], 5)
                . $this->pad(Str::limit((string) $project['name'], 22), 24)
                . $this->pad(\Mailer\Security\ApiKey::mask((string) $project['api_key_prefix']), 24)
                . $this->pad($usage['hour'] . ($project['rate_limit_hour'] > 0 ? '/' . $project['rate_limit_hour'] : ''), 10)
                . $this->pad($usage['day'] . ($project['rate_limit_day'] > 0 ? '/' . $project['rate_limit_day'] : ''), 10)
                . ((int) $project['active'] === 1 ? 'активен' : 'отключён')
            );
        }

        return 0;
    
    }
}
