<?php

declare(strict_types=1);

namespace Mailer\Domain;

/**
 * Все права панели. Список лежит в коде, а не в базе: право появляется вместе с
 * кодом, который его проверяет, и заводить под каждое миграцию незачем. В базе
 * хранятся только наборы прав у ролей (таблица roles).
 *
 * Право отвечает на вопрос «пускать ли в раздел», а не «чья это запись» — за второе
 * отвечает Scope. Исключение одно: data.all, оно как раз про чужие записи.
 */
final class Permission
{
    public const MESSAGES_VIEW   = 'messages.view';
    public const MESSAGES_SEND   = 'messages.send';
    public const MESSAGES_MANAGE = 'messages.manage';

    public const PROJECTS_VIEW   = 'projects.view';
    public const PROJECTS_MANAGE = 'projects.manage';

    public const TRANSPORTS_VIEW   = 'transports.view';
    public const TRANSPORTS_MANAGE = 'transports.manage';
    public const TRANSPORTS_TEST   = 'transports.test';

    public const TEMPLATES_VIEW   = 'templates.view';
    public const TEMPLATES_MANAGE = 'templates.manage';

    public const SUPPRESSIONS_VIEW   = 'suppressions.view';
    public const SUPPRESSIONS_MANAGE = 'suppressions.manage';

    public const WEBHOOKS_VIEW   = 'webhooks.view';
    public const WEBHOOKS_MANAGE = 'webhooks.manage';

    public const AUDIT_VIEW    = 'audit.view';
    public const LOGS_VIEW     = 'logs.view';
    public const SYSTEM_VIEW   = 'system.view';
    public const SYSTEM_MANAGE = 'system.manage';

    public const USERS_MANAGE = 'users.manage';
    public const ROLES_MANAGE = 'roles.manage';

    /** Видеть и править чужие записи, а не только свои */
    public const DATA_ALL = 'data.all';

    /**
     * Права по разделам — в этом же порядке они показываются в форме роли.
     *
     * @var array<string, array<string, string>>
     */
    public const GROUPS = [
        'Письма' => [
            self::MESSAGES_VIEW   => 'Смотреть письма и очередь',
            self::MESSAGES_SEND   => 'Писать и отправлять',
            self::MESSAGES_MANAGE => 'Повторять, отменять, удалять',
        ],
        'Проекты' => [
            self::PROJECTS_VIEW   => 'Смотреть проекты',
            self::PROJECTS_MANAGE => 'Заводить и править, выпускать ключи',
        ],
        'Транспорты' => [
            self::TRANSPORTS_VIEW   => 'Смотреть транспорты',
            self::TRANSPORTS_MANAGE => 'Заводить и править',
            self::TRANSPORTS_TEST   => 'Проверять связь',
        ],
        'Шаблоны' => [
            self::TEMPLATES_VIEW   => 'Смотреть шаблоны',
            self::TEMPLATES_MANAGE => 'Заводить и править',
        ],
        'Стоп-лист' => [
            self::SUPPRESSIONS_VIEW   => 'Смотреть закрытые адреса',
            self::SUPPRESSIONS_MANAGE => 'Закрывать и открывать адреса',
        ],
        'Вебхуки' => [
            self::WEBHOOKS_VIEW   => 'Смотреть очередь вебхуков',
            self::WEBHOOKS_MANAGE => 'Повторять и удалять доставки',
        ],
        'Сервис' => [
            self::AUDIT_VIEW    => 'Читать журнал действий',
            self::LOGS_VIEW     => 'Читать логи',
            self::SYSTEM_VIEW   => 'Смотреть состояние сервиса',
            self::SYSTEM_MANAGE => 'Перезапускать воркер, чистить очередь',
            self::USERS_MANAGE  => 'Управлять пользователями',
            self::ROLES_MANAGE  => 'Управлять ролями',
            self::DATA_ALL      => 'Доступ к чужим данным, а не только к своим',
        ],
    ];

    /**
     * Все коды прав одним списком.
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        $codes = [];
        foreach (self::GROUPS as $group) {
            foreach (array_keys($group) as $code) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    public static function known(string $code): bool
    {
        return in_array($code, self::all(), true);
    }

    /**
     * Подпись права для формы. Неизвестное показываем как есть — так видно,
     * что в базе осталось право от снятого раздела.
     */
    public static function label(string $code): string
    {
        foreach (self::GROUPS as $group) {
            if (isset($group[$code])) {
                return $group[$code];
            }
        }

        return $code;
    }

    /**
     * Оставляет только известные права, без повторов и в порядке реестра.
     *
     * @param array<int, mixed> $codes
     * @return array<int, string>
     */
    public static function filter(array $codes): array
    {
        $codes = array_map(static fn (mixed $code): string => (string) $code, $codes);

        return array_values(array_filter(self::all(), static fn (string $code): bool => in_array($code, $codes, true)));
    }

    /**
     * Набор администратора: всё, что есть.
     *
     * @return array<int, string>
     */
    public static function admin(): array
    {
        return self::all();
    }

    /**
     * Набор обычного пользователя: свои проекты, транспорты, шаблоны и письма.
     * Ни чужих данных, ни пользователей, ни состояния сервиса.
     *
     * @return array<int, string>
     */
    public static function user(): array
    {
        return [
            self::MESSAGES_VIEW,
            self::MESSAGES_SEND,
            self::MESSAGES_MANAGE,
            self::PROJECTS_VIEW,
            self::PROJECTS_MANAGE,
            self::TRANSPORTS_VIEW,
            self::TRANSPORTS_MANAGE,
            self::TRANSPORTS_TEST,
            self::TEMPLATES_VIEW,
            self::TEMPLATES_MANAGE,
            self::SUPPRESSIONS_VIEW,
            self::SUPPRESSIONS_MANAGE,
            self::WEBHOOKS_VIEW,
        ];
    }
}
