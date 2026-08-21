<?php

declare(strict_types=1);

use Mailer\Support\Env;

/**
 * Единственный файл настроек. Всё, что зависит от сервера или секретно, берём из .env,
 * здесь только значения по умолчанию.
 */

Env::load(MAILER_ROOT . '/.env');

return [
    // Название и режим сервиса
    'app' => [
        'name'     => Env::string('APP_NAME', 'Mailer'),
        'env'      => Env::string('APP_ENV', 'production'),
        'debug'    => Env::bool('APP_DEBUG', false),
        // Мастер-ключ для шифрования паролей SMTP в базе. Генерируется командой `php bin/mailer key:app`
        'key'      => Env::string('APP_KEY', ''),
        'timezone' => Env::string('APP_TIMEZONE', 'Europe/Moscow'),
        // Домен, от которого формируются Message-ID
        'hostname' => Env::string('APP_HOSTNAME', 'localhost'),
        // Адрес, по которому сервис доступен снаружи: нужен ссылке отписки в письмах
        'url'      => rtrim(Env::string('APP_URL', ''), '/'),
    ],

    // База данных: sqlite по умолчанию, mysql — когда появятся доступы
    'db' => [
        'driver'   => Env::string('DB_DRIVER', 'sqlite'),
        'sqlite'   => [
            'path' => Env::string('DB_SQLITE_PATH', MAILER_ROOT . '/var/mailer.sqlite'),
        ],
        'mysql'    => [
            'host'     => Env::string('DB_HOST', '127.0.0.1'),
            'port'     => Env::int('DB_PORT', 3306),
            'database' => Env::string('DB_DATABASE', 'mailer'),
            'username' => Env::string('DB_USERNAME', 'mailer'),
            'password' => Env::string('DB_PASSWORD', ''),
            'charset'  => Env::string('DB_CHARSET', 'utf8mb4'),
        ],
    ],

    // Куда сервис пишет свои файлы
    'paths' => [
        'log'   => MAILER_ROOT . '/var/log',
        'spool' => MAILER_ROOT . '/var/spool',
        'tmp'   => MAILER_ROOT . '/var/tmp',
    ],

    'log' => [
        // debug | info | warning | error
        'level' => Env::string('LOG_LEVEL', 'info'),
        // Писать ли в лог диалог с SMTP-сервером (полезно при отладке, шумно в проде)
        'smtp_conversation' => Env::bool('LOG_SMTP', false),
        // Сколько дней держать файлы логов (0 — не чистить)
        'keep_days' => Env::int('LOG_KEEP_DAYS', 30),
    ],

    // Очередь и повторные попытки
    'queue' => [
        'max_attempts'   => Env::int('QUEUE_MAX_ATTEMPTS', 5),
        // Пауза перед следующей попыткой, секунды. Берём по номеру попытки, дальше — последнее значение
        'backoff'        => [60, 300, 900, 3600, 10800],
        // Сколько писем воркер забирает за один заход
        'batch'          => Env::int('QUEUE_BATCH', 20),
        // Пауза воркера, когда очередь пуста, секунды
        'sleep'          => Env::int('QUEUE_SLEEP', 5),
        // Через сколько секунд «зависшее» письмо в статусе sending вернуть в очередь
        'stuck_after'    => Env::int('QUEUE_STUCK_AFTER', 900),
        // Сколько дней хранить отправленные письма (0 — хранить всегда)
        'keep_sent_days' => Env::int('QUEUE_KEEP_SENT_DAYS', 30),
    ],

    // Ограничения на письмо
    'limits' => [
        'max_recipients'      => Env::int('MAX_RECIPIENTS', 50),
        'max_attachment_size' => Env::int('MAX_ATTACHMENT_SIZE', 10 * 1024 * 1024),
        'max_message_size'    => Env::int('MAX_MESSAGE_SIZE', 25 * 1024 * 1024),
        'max_subject_length'  => 500,
    ],

    // Настройки SMTP-клиента (то, как мы ходим наружу)
    'smtp' => [
        'timeout'      => Env::int('SMTP_TIMEOUT', 30),
        'verify_peer'  => Env::bool('SMTP_VERIFY_PEER', true),
        'local_domain' => Env::string('SMTP_LOCAL_DOMAIN', ''),
        // Соединение живёт от письма к письму: рукопожатие TLS с авторизацией
        // дороже самой отправки. Сессия закрывается сама, когда очередь опустела
        'keepalive'     => Env::bool('SMTP_KEEPALIVE', true),
        // Сколько писем шлём в одной сессии (0 — сколько угодно): серверы
        // не любят вечных соединений и рвут их сами
        'session_limit' => Env::int('SMTP_SESSION_LIMIT', 100),
    ],

    // Локальный SMTP-релей (то, как к нам приходят письма от чужих приложений)
    'smtpd' => [
        'host'          => Env::string('SMTPD_HOST', '127.0.0.1'),
        'port'          => Env::int('SMTPD_PORT', 2525),
        'hostname'      => Env::string('SMTPD_HOSTNAME', 'mailer.local'),
        'max_size'      => Env::int('SMTPD_MAX_SIZE', 25 * 1024 * 1024),
        // Проект, от имени которого принимаем письма без авторизации
        'project'       => Env::string('SMTPD_PROJECT', 'local-relay'),
        // Если задать логин и пароль — релей потребует AUTH
        'auth_user'     => Env::string('SMTPD_AUTH_USER', ''),
        'auth_password' => Env::string('SMTPD_AUTH_PASSWORD', ''),
    ],

    // sendmail-shim: под каким проектом принимаем письма из stdin
    'sendmail' => [
        'project' => Env::string('SENDMAIL_PROJECT', 'local-sendmail'),
    ],

    // Вебхуки о статусе письма
    'webhook' => [
        'timeout'      => Env::int('WEBHOOK_TIMEOUT', 10),
        'max_attempts' => Env::int('WEBHOOK_MAX_ATTEMPTS', 5),
        'backoff'      => [30, 120, 600, 1800, 7200],
    ],

    // Отписка одной кнопкой (List-Unsubscribe, RFC 8058)
    'unsubscribe' => [
        // Включает заголовки отписки во всех письмах; у проекта можно выключить
        'enabled' => Env::bool('UNSUBSCRIBE_ENABLED', false),
    ],

    // Ящик, куда возвращаются недоставленные письма
    'bounce' => [
        'enabled'    => Env::bool('BOUNCE_ENABLED', false),
        'host'       => Env::string('BOUNCE_HOST', ''),
        'port'       => Env::int('BOUNCE_PORT', 995),
        // ssl | tls | none
        'encryption' => Env::string('BOUNCE_ENCRYPTION', 'ssl'),
        'username'   => Env::string('BOUNCE_USERNAME', ''),
        'password'   => Env::string('BOUNCE_PASSWORD', ''),
        // Удалять разобранные письма из ящика (иначе следующий заход прочитает их снова)
        'delete'     => Env::bool('BOUNCE_DELETE', true),
        // Сколько писем забирать за один заход
        'limit'      => Env::int('BOUNCE_LIMIT', 50),
        // Как часто воркер заглядывает в ящик, секунды
        'interval'   => Env::int('BOUNCE_INTERVAL', 300),
        // Обратный адрес для отказов. С verp письмо уходит с адресом вида
        // bounce+<uuid>@домен — по нему отказ сразу привязывается к письму.
        // Яндекс и подобные такой конверт не примут: включать на своём сервере
        'address'    => Env::string('BOUNCE_ADDRESS', ''),
        'verp'       => Env::bool('BOUNCE_VERP', false),
    ],

    // Стоп-лист адресов
    'suppression' => [
        // Закрывать адрес самим, когда сервер получателя ответил «нет такого ящика»
        'auto_bounce' => Env::bool('SUPPRESSION_AUTO_BOUNCE', true),
    ],

    // Метрики для Prometheus (адрес /metrics)
    'metrics' => [
        'enabled' => Env::bool('METRICS_ENABLED', true),
        // Пустой токен — адрес открыт, закрывайте его на nginx
        'token'   => Env::string('METRICS_TOKEN', ''),
    ],

    // Веб-панель
    'ui' => [
        'per_page' => Env::int('UI_PER_PAGE', 30),
        // Сколько секунд держать сводки обзора в кэше (0 — считать каждый раз)
        'stats_cache' => Env::int('UI_STATS_CACHE', 30),
        // Вход по логину и паролю. Выключают, если панель уже закрыта basic auth на nginx
        'auth' => Env::bool('UI_AUTH', true),
        // Через сколько секунд без активности снова спрашивать пароль (0 — не спрашивать)
        'session_lifetime' => Env::int('UI_SESSION_LIFETIME', 43200),
        // Разрешить кнопки «повторить», «отменить», «удалить», «тестовое письмо»
        'allow_actions' => Env::bool('UI_ALLOW_ACTIONS', true),
        // Сколько дней хранить журнал действий панели (0 — хранить всегда)
        'audit_keep_days' => Env::int('UI_AUDIT_KEEP_DAYS', 180),
    ],
];
