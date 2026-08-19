<?php

declare(strict_types=1);

namespace Mailer\Ui;

use Mailer\Support\MailerException;

/**
 * Записи с таким id нет. Панель на это отвечает одинаково везде: сообщение и
 * возврат к списку раздела — поэтому кидаем исключение, а разбирается с ним ядро.
 */
final class RecordNotFound extends MailerException
{
    private string $route;

    public function __construct(string $message, string $route)
    {
        parent::__construct($message);

        $this->route = $route;
    }

    /**
     * Имя маршрута, куда возвращать пользователя.
     */
    public function route(): string
    {
        return $this->route;
    }
}
