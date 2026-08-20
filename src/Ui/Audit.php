<?php

declare(strict_types=1);

namespace Mailer\Ui;

use Mailer\Repository\AuditRepository;
use Mailer\Support\Logger;
use Throwable;

/**
 * Запись действия в журнал панели: Audit::updated('project', $id, 'проект «Сайт»').
 *
 * Помощник статический намеренно: вызовы стоят в десятке контроллеров, и тащить
 * репозиторий в каждый конструктор ради одной строки незачем. Пользователя берём
 * там же, где его берут вьюхи, — у Auth.
 *
 * Журнал никогда не мешает работе: упавшая запись уходит в лог, а действие
 * пользователя доводится до конца. Так панель переживает случай, когда код
 * приехал раньше миграции и таблицы audit_log ещё нет.
 */
final class Audit
{
    public static function created(string $entity, ?int $id, string $summary = ''): void
    {
        self::log(AuditRepository::CREATED, $entity, $id, $summary);
    }

    public static function updated(string $entity, ?int $id, string $summary = ''): void
    {
        self::log(AuditRepository::UPDATED, $entity, $id, $summary);
    }

    public static function deleted(string $entity, ?int $id, string $summary = ''): void
    {
        self::log(AuditRepository::DELETED, $entity, $id, $summary);
    }

    /**
     * Действие, которое ничего не создаёт и не удаляет: перезапуск воркера,
     * повтор письма, проверка транспорта.
     */
    public static function action(string $entity, ?int $id, string $summary = ''): void
    {
        self::log(AuditRepository::ACTION, $entity, $id, $summary);
    }

    public static function log(string $action, string $entity, ?int $id = null, string $summary = ''): void
    {
        try {
            $viewer = Auth::viewer();

            (new AuditRepository())->log(
                $viewer->id(),
                $viewer->login(),
                $action,
                $entity,
                $id,
                $summary,
                self::ip()
            );
        } catch (Throwable $e) {
            (new Logger('ui'))->warning('Не удалось записать действие в журнал', [
                'action' => $action,
                'entity' => $entity,
                'error'  => $e->getMessage(),
            ]);
        }
    }

    /**
     * Вход и выход пишем отдельно от Auth: там же считаются неудачные попытки,
     * а в журнале нужен только результат.
     */
    public static function login(int $userId, string $login, bool $success): void
    {
        self::write(
            $userId,
            $login,
            $success ? AuditRepository::LOGIN : AuditRepository::ACTION,
            'user',
            $userId > 0 ? $userId : null,
            $success ? 'вход в панель' : 'неудачная попытка входа'
        );
    }

    public static function logout(int $userId, string $login): void
    {
        self::write($userId, $login, AuditRepository::LOGOUT, 'user', $userId > 0 ? $userId : null, 'выход из панели');
    }

    /**
     * Запись от имени конкретного пользователя — когда сессии ещё или уже нет.
     */
    private static function write(int $userId, string $login, string $action, string $entity, ?int $id, string $summary): void
    {
        try {
            (new AuditRepository())->log($userId, $login, $action, $entity, $id, $summary, self::ip());
        } catch (Throwable $e) {
            (new Logger('ui'))->warning('Не удалось записать вход в журнал', ['error' => $e->getMessage()]);
        }
    }

    private static function ip(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    }
}
