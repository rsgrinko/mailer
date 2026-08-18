<?php

declare(strict_types=1);

namespace Mailer\Ui;

use Mailer\Support\MailerException;

/**
 * Отрисовка страниц панели. Шаблоны — обычные PHP-файлы в src/Ui/views.
 */
final class View
{
    /**
     * Рендерит шаблон внутри общего каркаса.
     *
     * @param array<string, mixed> $data
     */
    public static function render(string $template, array $data = [], string $title = ''): string
    {
        $content = self::partial($template, $data);

        return self::partial('layout', [
            'content' => $content,
            'title'   => $title !== '' ? $title : 'Панель',
            'flash'   => self::takeFlash(),
            'active'  => $data['active'] ?? '',
        ]);
    }

    /**
     * Рендерит шаблон без каркаса.
     *
     * @param array<string, mixed> $data
     */
    public static function partial(string $template, array $data = []): string
    {
        $file = MAILER_ROOT . '/src/Ui/views/' . $template . '.php';

        if (!is_file($file)) {
            throw new MailerException('Шаблон панели не найден: ' . $template);
        }

        extract($data, EXTR_SKIP);

        ob_start();
        include $file;

        return (string) ob_get_clean();
    }

    /**
     * Экранирование для вывода в HTML.
     */
    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Сообщение, которое покажется на следующей странице.
     */
    public static function flash(string $message, string $type = 'ok'): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        $_SESSION['flash'][] = ['message' => $message, 'type' => $type];
    }

    /**
     * Забирает накопленные сообщения и очищает их.
     *
     * @return array<int, array{message: string, type: string}>
     */
    private static function takeFlash(): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        $flash             = $_SESSION['flash'] ?? [];
        $_SESSION['flash'] = [];

        return is_array($flash) ? $flash : [];
    }

    /**
     * Человеческая дата.
     */
    public static function date(?string $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? $value : date('d.m.Y H:i:s', $timestamp);
    }

    /**
     * «5 минут назад» — так понятнее, чем голая дата.
     */
    public static function ago(?string $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return $value;
        }

        $diff = time() - $timestamp;
        if ($diff < 0) {
            $diff = abs($diff);
            $suffix = ' вперёд';
        } else {
            $suffix = ' назад';
        }

        return match (true) {
            $diff < 60      => $diff . ' с' . $suffix,
            $diff < 3600    => (int) ($diff / 60) . ' мин' . $suffix,
            $diff < 86400   => (int) ($diff / 3600) . ' ч' . $suffix,
            default         => (int) ($diff / 86400) . ' дн' . $suffix,
        };
    }

    /**
     * Ссылка с сохранением текущих фильтров.
     *
     * @param array<string, mixed> $params
     */
    public static function url(string $path, array $params = []): string
    {
        $params = array_filter($params, static fn ($value): bool => $value !== '' && $value !== null);

        return '/ui' . $path . ($params === [] ? '' : '?' . http_build_query($params));
    }
}
