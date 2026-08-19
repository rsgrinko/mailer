<?php

declare(strict_types=1);

namespace Mailer\Console;

use Mailer\Support\Config;
use Throwable;

/**
 * Консольная утилита: `php bin/mailer <команда> [аргументы]`.
 *
 * Здесь только разбор аргументов, реестр команд и справка. Сама команда — класс
 * в Console/Commands: добавили файл, вписали в COMMANDS — и она появилась в help.
 */
final class Application
{
    /**
     * Команды по разделам — в этом же порядке печатается справка.
     *
     * @var array<string, array<int, class-string<Command>>>
     */
    private const COMMANDS = [
        'Установка и обслуживание' => [
            Commands\MigrateCommand::class,
            Commands\SeedCommand::class,
            Commands\AppKeyCommand::class,
            Commands\StatusCommand::class,
            Commands\RouteListCommand::class,
            Commands\LogsPurgeCommand::class,
        ],
        'Работа очереди' => [
            Commands\WorkerCommand::class,
            Commands\WorkerRestartCommand::class,
            Commands\SmtpdCommand::class,
            Commands\QueueStatusCommand::class,
            Commands\QueueRetryCommand::class,
            Commands\QueueCancelCommand::class,
            Commands\QueuePurgeCommand::class,
            Commands\WebhookProcessCommand::class,
        ],
        'Проекты и ключи' => [
            Commands\KeyCreateCommand::class,
            Commands\KeyListCommand::class,
            Commands\KeyRegenerateCommand::class,
            Commands\KeyRevokeCommand::class,
        ],
        'Пользователи панели' => [
            Commands\UserCreateCommand::class,
            Commands\UserListCommand::class,
            Commands\UserPasswordCommand::class,
            Commands\UserDeleteCommand::class,
        ],
        'Транспорты и шаблоны' => [
            Commands\TransportAddCommand::class,
            Commands\TransportListCommand::class,
            Commands\TransportTestCommand::class,
            Commands\TransportDefaultCommand::class,
            Commands\TransportDeleteCommand::class,
            Commands\TemplateListCommand::class,
            Commands\TemplateDeleteCommand::class,
        ],
        'Отправка и проверка' => [
            Commands\SendTestCommand::class,
            Commands\SendCommand::class,
            Commands\TestCommand::class,
        ],
    ];

    /** @var array<int, string> Позиционные аргументы */
    private array $args = [];

    /** @var array<string, string> Опции вида --name=value */
    private array $options = [];

    /**
     * @param array<int, string> $argv
     */
    public function run(array $argv): int
    {
        $name = $argv[1] ?? 'help';
        $this->parseArguments(array_slice($argv, 2));

        if (in_array($name, ['help', '--help', '-h'], true)) {
            return $this->help();
        }

        $command = $this->find($name);

        if ($command === null) {
            return $this->unknown($name);
        }

        try {
            return $command->withInput($this->args, $this->options)->run();
        } catch (Throwable $e) {
            $this->line('ОШИБКА: ' . $e->getMessage());

            if ((bool) Config::get('app.debug', false)) {
                $this->line($e->getFile() . ':' . $e->getLine());
            }

            return 1;
        }
    }

    /**
     * Все команды сервиса — пригождается справке и тестам.
     *
     * @return array<int, Command>
     */
    public static function commands(): array
    {
        $commands = [];

        foreach (self::COMMANDS as $group) {
            foreach ($group as $class) {
                $commands[] = new $class();
            }
        }

        return $commands;
    }

    private function find(string $name): ?Command
    {
        foreach (self::commands() as $command) {
            if ($command->name() === $name) {
                return $command;
            }
        }

        return null;
    }

    private function help(): int
    {
        $this->line('Сервис отправки почты — консольная утилита');
        $this->line('');
        $this->line('Использование: php bin/mailer <команда> [аргументы]');

        foreach (self::COMMANDS as $title => $classes) {
            $this->line('');
            $this->line($title . ':');

            foreach ($classes as $class) {
                /** @var Command $command */
                $command = new $class();
                $usage   = $command->usage();

                // Длинную строку вызова не растягиваем колонкой, пишем описание следующей строкой
                if (mb_strlen($usage) > 40) {
                    $this->line('  ' . $usage);
                    $this->line('      ' . $command->description());

                    continue;
                }

                $this->line('  ' . $this->pad($usage, 42) . $command->description());
            }
        }

        $this->line('');
        $this->line('Настройки берутся из .env, см. .env.example.');

        return 0;
    }

    private function unknown(string $command): int
    {
        $this->line('Неизвестная команда: ' . $command);
        $this->line('Список команд: php bin/mailer help');

        return 1;
    }

    /**
     * @param array<int, string> $arguments
     */
    private function parseArguments(array $arguments): void
    {
        foreach ($arguments as $argument) {
            if (str_starts_with($argument, '--')) {
                $argument = substr($argument, 2);

                if (str_contains($argument, '=')) {
                    [$name, $value]       = explode('=', $argument, 2);
                    $this->options[$name] = $value;
                } else {
                    $this->options[$argument] = '1';
                }

                continue;
            }

            $this->args[] = $argument;
        }
    }

    private function pad(string $text, int $width): string
    {
        $length = mb_strlen($text);

        return $length >= $width ? $text . ' ' : $text . str_repeat(' ', $width - $length);
    }

    private function line(string $text = ''): void
    {
        echo $text . PHP_EOL;
    }
}
