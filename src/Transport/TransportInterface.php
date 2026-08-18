<?php

declare(strict_types=1);

namespace Mailer\Transport;

use Mailer\Message\Message;

/**
 * Способ доставки письма. Все транспорты умеют одно и то же: отправить письмо
 * или бросить TransportException.
 */
interface TransportInterface
{
    /**
     * Отправляет письмо. Возвращает короткое описание результата — оно попадёт
     * в историю письма (например, ответ SMTP-сервера).
     *
     * @throws TransportException
     */
    public function send(Message $message): string;

    /**
     * Имя транспорта из базы — им помечаем отправленные письма.
     */
    public function name(): string;

    /**
     * Тип: smtp, sendmail, log, null, failover, roundrobin.
     */
    public function type(): string;

    /**
     * Проверка настроек: подключиться, авторизоваться и отключиться, ничего не отправляя.
     * Возвращает текстовый отчёт для CLI и панели.
     *
     * @throws TransportException
     */
    public function test(): string;
}
