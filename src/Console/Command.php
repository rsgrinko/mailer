<?php

declare(strict_types=1);

namespace Mailer\Console;

/**
 * Одна команда консольной утилиты.
 *
 * Новая команда — новый класс в Console/Commands и строка в реестре Application:
 * имя, описание для справки и метод run(). Аргументы и опции разбирает Application
 * и передаёт готовыми.
 */
abstract class Command
{
    /** @var array<int, string> Позиционные аргументы */
    protected array $args = [];

    /** @var array<string, string> Опции вида --name=value */
    protected array $options = [];

    /** @var callable(string): void Куда печатать — подменяется в тестах */
    private $output;

    public function __construct()
    {
        $this->output = static function (string $line): void {
            echo $line . PHP_EOL;
        };
    }

    /**
     * Имя команды: 'queue:retry'.
     */
    abstract public function name(): string;

    /**
     * Строка для справки — что команда делает.
     */
    abstract public function description(): string;

    /**
     * Как её звать: 'queue:retry <id|--failed>'. По умолчанию — просто имя.
     */
    public function usage(): string
    {
        return $this->name();
    }

    /**
     * Выполняет команду и возвращает код выхода: 0 — всё хорошо.
     */
    abstract public function run(): int;

    /**
     * @param array<int, string>    $args
     * @param array<string, string> $options
     */
    public function withInput(array $args, array $options, ?callable $output = null): static
    {
        $this->args    = $args;
        $this->options = $options;

        if ($output !== null) {
            $this->output = $output;
        }

        return $this;
    }

    protected function line(string $text = ''): void
    {
        ($this->output)($text);
    }

    /**
     * Колонка нужной ширины. str_pad считает байты, а в русских словах их вдвое больше.
     */
    protected function pad(string $text, int $width): string
    {
        $length = mb_strlen($text);

        return $length >= $width ? $text . ' ' : $text . str_repeat(' ', $width - $length);
    }

    protected function arg(int $index, string $default = ''): string
    {
        return $this->args[$index] ?? $default;
    }

    protected function option(string $name, ?string $default = null): ?string
    {
        return $this->options[$name] ?? $default;
    }

    protected function hasOption(string $name): bool
    {
        return isset($this->options[$name]);
    }
}
