<?php

declare(strict_types=1);

namespace Mailer\Storage;

use Mailer\Support\MailerException;

/**
 * Ошибка базы данных: не подключились, не выполнился запрос.
 *
 * Отдельный класс нужен, чтобы такие ошибки не уезжали клиенту текстом:
 * в них видны имена таблиц, пользователь БД и куски SQL.
 */
final class StorageException extends MailerException
{
}
