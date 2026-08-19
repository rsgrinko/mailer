<?php

declare(strict_types=1);

namespace Mailer\Ui;

use Mailer\Http\Router;
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
            'user'    => Auth::enabled() ? Auth::user() : null,
            'bare'    => false,
        ]);
    }

    /**
     * Страница без шапки и меню — вход и первичная настройка.
     *
     * @param array<string, mixed> $data
     */
    public static function renderBare(string $template, array $data = [], string $title = ''): string
    {
        return self::partial('layout', [
            'content' => self::partial($template, $data),
            'title'   => $title !== '' ? $title : 'Панель',
            'flash'   => self::takeFlash(),
            'active'  => '',
            'user'    => null,
            'bare'    => true,
        ]);
    }

    /**
     * Рендерит шаблон без каркаса.
     *
     * @param array<string, mixed> $data
     */
    public static function partial(string $viewName, array $viewData = []): string
    {
        // Имена переменных с подчёркиванием, чтобы не столкнуться с данными страницы:
        // на странице шаблонов есть своя переменная $template, и раньше она затиралась
        $__file = MAILER_ROOT . '/src/Ui/views/' . $viewName . '.php';

        if (!is_file($__file)) {
            throw new MailerException('Шаблон панели не найден: ' . $viewName);
        }

        extract($viewData, EXTR_OVERWRITE);

        ob_start();
        include $__file;

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
        Auth::start();

        $_SESSION['flash'][] = ['message' => $message, 'type' => $type];
    }

    /**
     * Забирает накопленные сообщения и очищает их.
     *
     * @return array<int, array{message: string, type: string}>
     */
    private static function takeFlash(): array
    {
        Auth::start();

        $flash             = $_SESSION['flash'] ?? [];
        $_SESSION['flash'] = [];

        return is_array($flash) ? $flash : [];
    }

    /**
     * Статус письма по-русски — в панели работает человек, а не машина.
     */
    public static function status(string $status): string
    {
        return match ($status) {
            'queued'   => 'в очереди',
            'sending'  => 'отправляется',
            'sent'     => 'отправлено',
            'failed'   => 'ошибка',
            'canceled' => 'отменено',
            default    => $status,
        };
    }

    /**
     * Статус доставки вебхука.
     */
    public static function webhookStatus(string $status): string
    {
        return match ($status) {
            'queued'    => 'в очереди',
            'delivered' => 'доставлен',
            'failed'    => 'не доставлен',
            default     => $status,
        };
    }

    /**
     * Откуда пришло письмо.
     */
    public static function source(string $source): string
    {
        return match ($source) {
            'api'      => 'API',
            'sendmail' => 'sendmail',
            'smtpd'    => 'SMTP-релей',
            'cli'      => 'командная строка',
            'ui'       => 'панель',
            default    => $source,
        };
    }

    /**
     * Событие в истории письма.
     */
    public static function event(string $type): string
    {
        return match ($type) {
            'accepted' => 'принято',
            'attempt'  => 'попытка',
            'sent'     => 'отправлено',
            'failed'   => 'ошибка',
            'retry'    => 'повтор',
            'canceled' => 'отменено',
            'requeued' => 'возвращено в очередь',
            'webhook'  => 'вебхук',
            default    => $type,
        };
    }

    /**
     * Тип транспорта.
     */
    public static function transportType(string $type): string
    {
        return match ($type) {
            'smtp'       => 'SMTP',
            'sendmail'   => 'sendmail',
            'log'        => 'запись в файлы',
            'null'       => 'заглушка',
            'failover'   => 'цепочка',
            'roundrobin' => 'по кругу',
            default      => $type,
        };
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
     * Адрес маршрута по его имени: View::route('ui.messages.show', ['id' => 5]).
     * Лишние параметры уходят в query-строку — так удобно тащить фильтры по страницам.
     *
     * @param array<string, mixed> $params
     */
    public static function route(string $name, array $params = []): string
    {
        return Router::url($name, array_filter($params, static fn ($value): bool => $value !== '' && $value !== null));
    }
}
