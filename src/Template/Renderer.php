<?php

declare(strict_types=1);

namespace Mailer\Template;

/**
 * Подстановка переменных в шаблоны писем.
 *
 *   {{ name }}        — значение с экранированием HTML (в HTML-части)
 *   {{{ name }}}      — значение как есть, без экранирования
 *   {{ user.email }}  — можно обращаться к вложенным данным
 */
final class Renderer
{
    /**
     * @param array<string, mixed> $data
     */
    public function render(string $template, array $data, bool $escape = true): string
    {
        if ($template === '') {
            return '';
        }

        // Сначала «сырые» вставки в тройных скобках
        $result = preg_replace_callback(
            '/\{\{\{\s*([a-zA-Z0-9_.\-]+)\s*\}\}\}/',
            fn (array $m): string => $this->stringify($this->value($data, $m[1])),
            $template
        ) ?? $template;

        return preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_.\-]+)\s*\}\}/',
            function (array $m) use ($data, $escape): string {
                $value = $this->stringify($this->value($data, $m[1]));

                return $escape ? htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : $value;
            },
            $result
        ) ?? $result;
    }

    /**
     * Готовит письмо по шаблону из базы.
     *
     * @param array<string, mixed> $template строка таблицы templates
     * @param array<string, mixed> $data
     * @return array{subject: string, html: string, text: string}
     */
    public function renderTemplate(array $template, array $data): array
    {
        return [
            'subject' => $this->render((string) ($template['subject'] ?? ''), $data, false),
            'html'    => $this->render((string) ($template['html'] ?? ''), $data, true),
            'text'    => $this->render((string) ($template['text'] ?? ''), $data, false),
        ];
    }

    /**
     * Список переменных, которые встречаются в шаблоне — показываем в панели.
     *
     * @return array<int, string>
     */
    public function variables(string ...$templates): array
    {
        $found = [];

        foreach ($templates as $template) {
            if (preg_match_all('/\{\{\{?\s*([a-zA-Z0-9_.\-]+)\s*\}?\}\}/', $template, $matches) > 0) {
                foreach ($matches[1] as $name) {
                    $found[$name] = true;
                }
            }
        }

        return array_keys($found);
    }

    /**
     * Значение по пути 'user.name'.
     *
     * @param array<string, mixed> $data
     */
    private function value(array $data, string $path): mixed
    {
        $value = $data;

        foreach (explode('.', $path) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return '';
            }
            $value = $value[$part];
        }

        return $value;
    }

    private function stringify(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'да' : 'нет';
        }
        if (is_array($value)) {
            return implode(', ', array_map(fn ($item): string => $this->stringify($item), $value));
        }
        if ($value === null) {
            return '';
        }

        return (string) $value;
    }
}
