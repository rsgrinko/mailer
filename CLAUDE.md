# CLAUDE.md — сервис отправки E-Mail

Документ для Claude Code и разработчиков: назначение, архитектура, соглашения и правила
работы с кодом. Всё общение и документация по проекту — **на русском языке**.
Отвечать на запросы максимально четко и коротко без лишней "воды". Вежливость также можно отбросить.

## 1. Что это

Самостоятельный HTTP-сервис отправки писем на чистом PHP 8.1+ **без composer и без единой
внешней зависимости**. Принимает письма по HTTP API, складывает их в очередь, а фоновый
воркер доставляет через настраиваемые транспорты (SMTP Яндекса и любой другой SMTP,
sendmail, log/null, failover- и round-robin-цепочки).

Потребители сервиса:

1. PHP-проекты — через мини-SDK (`integrations/php-sdk/MailerClient.php`, один файл, `require` и всё).
2. Любые приложения по HTTP — `curl` или скрипт `bin/mailer-send`.
3. Легаси-приложения без возможности правки кода — через sendmail-shim
   (`bin/mailer-sendmail`, прописывается в `sendmail_path`) или локальный SMTP-релей
   (`127.0.0.1:2525`).

## 2. Жёсткие правила проекта

- **Никаких внешних зависимостей и composer.** Только PHP и стандартные расширения:
  `pdo_sqlite` / `pdo_mysql`, `openssl`, `mbstring`, `json`. Сеть — через потоки
  (`stream_socket_client`). `curl` опционален (есть fallback на потоки).
- **Автозагрузка своя** — `src/Autoload.php`, PSR-4-подобная (`Mailer\` -> `src/`).
- **Строгая типизация**: каждый PHP-файл начинается с `declare(strict_types=1);`.
- **Секреты не хранятся в коде и не коммитятся.** Чувствительные значения — в `.env`
  (в репозитории только `.env.example`). Пароли SMTP в БД зашифрованы (AES-256-GCM,
  мастер-ключ `APP_KEY`). API-ключи проектов хранятся только хешами.
- **Комментарии, сообщения об ошибках и документация — на русском.**
- **База считается боевой.** Данные, которые в ней уже есть, не удаляем и не затираем:
  никаких `DELETE`, `TRUNCATE`, `DROP`, чисток «мусора», перевыпуска ключей и сброса
  паролей по своей инициативе — ни в MySQL, ни в SQLite, ни ради проверки. Если для
  работы нужно что-то удалить, сначала спрашиваем.
  Заводить новое (проекты, письма, транспорты, шаблоны, пользователей) можно;
  после проверок свои записи убираем точечно, по id, а не по маске.
  Миграции накатывать можно, но **только с подтверждения** — они меняют боевую схему.
- Код одинаково работает на Linux (продакшен: nginx + php-fpm) и Windows (разработка: `php -S`).
- Пути внутри проекта строятся от константы `MAILER_ROOT`, разделители — прямые слэши.

## 2.1. Авторство и стиль

- Автор проекта — владелец репозитория. В коде, документации, комментариях, метаданных и
  истории коммитов **не должно быть следов того, что что-то писал ИИ**: ни упоминаний
  ассистентов, ни соавторства, ни служебных подписей, ни характерных штампов вроде
  «сгенерировано», «AI», «assistant».
- Комментарии пишутся так, как их писал бы разработчик коллеге: по-русски, по делу,
  без пересказа очевидного и без канцелярита.
- Коммиты: короткие сообщения в одну строку, на русском, по существу изменения
  («добавил повторы в очереди», «починил разбор Bcc»). Никаких блоков Co-Authored-By,
  ссылок на сессии и прочих следов инструментов.

## 3. Структура каталогов

```
config/              конфигурация (config.php читает .env, значения по умолчанию)
src/                 ядро сервиса, namespace Mailer\
  Support/           Env, Config, Logger, Str, Uuid, Validator, MailerException
  Storage/           Database (обёртка PDO, диалекты sqlite/mysql), Migrator
  Repository/        доступ к таблицам: Message, Project, Transport, Template, Event, Webhook
  Security/          ApiKey (генерация/хеш), Crypto (шифрование настроек транспорта)
  Message/           Message (DTO), Address, Attachment, MimeBuilder, MimeParser, Encoder
  Dkim/              Signer — DKIM-подпись rsa-sha256, relaxed/relaxed
  Transport/         TransportInterface, SmtpClient, SmtpTransport, SendmailTransport,
                     LogTransport, NullTransport, FailoverTransport, RoundRobinTransport, Factory
  Queue/             Queue (enqueue/claim/complete/fail + backoff), Worker
  RateLimit/         RateLimiter — лимиты на проект и на транспорт
  Webhook/           Sender — доставка вебхуков с HMAC-подписью и ретраями
  Template/          Renderer — шаблоны с подстановкой {{ переменных }}
  Http/              Router, Request, Response, Kernel, Controllers/ (API)
  Ui/                контроллеры и вьюхи веб-панели мониторинга
  Smtpd/             SmtpServer — локальный SMTP-релей для приложений без SDK
  Console/           Application + Commands/ (CLI-команды)
public/index.php     единая точка входа: /api/v1/... и /ui/... (у панели свой вход
                     по логину и паролю, см. src/Ui/Auth.php)
bin/mailer           основная CLI-утилита (миграции, воркер, ключи, транспорты, тесты)
bin/mailer-sendmail  sendmail-совместимый shim (stdin -> очередь)
bin/mailer-send      shell-утилита отправки через HTTP API
integrations/        всё, что ставится на сторону клиента, а не сервиса:
  php-sdk/           мини-SDK для PHP-проектов (один файл) + примеры
  dokuwiki/          плагин mailerservice для вики на DokuWiki
tests/               собственный мини-тестраннер (без PHPUnit) и тесты
deploy/              nginx.conf, systemd-юниты, шпаргалки по установке
docs/                документация: API, SDK, интеграции, деплой, эксплуатация
var/                 runtime: SQLite-база, логи, spool вложений, .eml из log-транспорта
```

## 4. Поток данных

```
клиент (SDK / curl / sendmail-shim / SMTP-релей / веб-панель)
        |
        v
  приём и валидация -> запись в БД (messages, status=queued) + вложения в var/spool
        |                                   |
        | sync=true                         | по умолчанию (асинхронно)
        v                                   v
   отправка сразу                    bin/mailer worker (демон/cron)
        |                                   |
        +------------> транспорт <----------+
                          |
             sent / failed (retry с backoff) -> message_events -> вебхук проекта
```

Статусы сообщения: `queued` -> `sending` -> `sent` | `failed` | `canceled`.
Временные ошибки SMTP (4xx, обрыв связи) -> снова `queued` с `available_at = now + backoff`,
пока не исчерпан `max_attempts`. Постоянные (5xx, отказ RCPT) -> сразу `failed`.

## 5. Модель данных

- `projects` — клиенты API: имя, префикс и хеш API-ключа, транспорт по умолчанию,
  лимиты (в час/сутки), URL и секрет вебхука, флаг активности.
- `transports` — профили отправки: тип (`smtp|sendmail|log|null|failover|roundrobin`),
  JSON-настройки (пароль зашифрован), приоритет, суточный лимит, активность.
- `messages` — письма: envelope, заголовки, тела, метаданные вложений, статус, попытки,
  `available_at`, ошибки, транспорт, источник (`api|sendmail|smtpd|cli|ui`).
- `message_events` — история: принято, попытка, отправлено, ошибка, ретрай, вебхук.
- `templates` — шаблоны писем (тема, html, text) с переменными `{{ var }}`.
- `webhook_deliveries` — очередь доставки вебхуков.
- `counters` — счётчики для rate limit (ключ + окно) и неудачных попыток входа в панель.
- `users` — пользователи панели: логин, хеш пароля, имя, активность, последний вход.

Обе СУБД поддерживаются одним кодом: `Database` знает диалект и подставляет нужный SQL
(автоинкремент, `INSERT ... ON CONFLICT` против `ON DUPLICATE KEY UPDATE`, блокировки при
claim). По умолчанию — SQLite (`var/mailer.sqlite`), MySQL включается через `DB_DRIVER=mysql`.

## 6. HTTP API (v1)

Авторизация: `Authorization: Bearer <api-key>`; ключ выпускается командой
`php bin/mailer key:create`. Тело — JSON, ответ — JSON, ошибки — `{"error": {...}}`.

| Метод  | Путь                            | Назначение                                              |
|--------|---------------------------------|---------------------------------------------------------|
| POST   | `/api/v1/messages`              | поставить письмо в очередь (или отправить сразу, `sync`) |
| GET    | `/api/v1/messages/{id}`         | статус письма                                            |
| GET    | `/api/v1/messages`              | список писем проекта с фильтрами                         |
| POST   | `/api/v1/messages/{id}/retry`   | повторить неудачное письмо                               |
| DELETE | `/api/v1/messages/{id}`         | отменить письмо в очереди                                |
| GET    | `/api/v1/templates`             | список доступных шаблонов                                |
| GET    | `/api/v1/health`                | статус сервиса, БД, очереди                              |

## 7. Веб-панель

`/ui/` — страницы без JS-фреймворков: дашборд со статистикой, очередь и история писем с
фильтрами, карточка письма (заголовки, HTML-превью в песочнице, текст, raw, события,
вложения), управление транспортами, проектами, шаблонами, пользователями, просмотр вебхуков
и логов, действия «повторить», «отменить», «отправить тестовое письмо».

Вход — по логину и паролю (`src/Ui/Auth.php`, таблица `users`): пользователей может быть
сколько угодно, права у всех одинаковые, единственное требование к паролю — от 6 символов
(`src/Security/Password.php`). Пароли хранятся хешами (`password_hash`), сессия — кука
`mailer_panel` с `HttpOnly` и `SameSite=Lax`, после 10 неудачных попыток вход с этого адреса
блокируется на 15 минут. Пустая таблица `users` включает страницу первого запуска
`/ui/setup`. Авторизацию можно выключить настройкой `UI_AUTH=false` — тогда панель снова
закрывается только средствами nginx.

## 8. Команды CLI

```
php bin/mailer migrate               применить миграции
php bin/mailer seed                  базовые данные (транспорты, шаблоны)
php bin/mailer worker [--once]       воркер очереди
php bin/mailer worker:restart        пометка в settings: воркер доработает пачку и выйдет
php bin/mailer smtpd                 локальный SMTP-релей
php bin/mailer key:create|key:list|key:revoke
php bin/mailer user:create|user:list|user:password|user:delete   пользователи панели
php bin/mailer transport:add|transport:list|transport:test|transport:default
php bin/mailer queue:status|queue:retry|queue:purge
php bin/mailer send:test <email>     тестовое письмо
php bin/mailer test                  мини-тестраннер
```

## 9. Соглашения по коду

- Классы — `PascalCase`, методы и свойства — `camelCase`, константы — `UPPER_SNAKE`.
- Один класс — один файл, путь соответствует namespace.
- **Только фигурные скобки.** Альтернативный синтаксис (`if (…): … endif;`, `foreach (…): …
  endforeach;`, `while`, `for`, `switch`) не используется нигде, включая шаблоны панели:
  ```php
  <?php foreach ($items as $item) { ?>
      <tr>…</tr>
  <?php } ?>
  ```
  Тело условия и цикла всегда в скобках, даже если внутри одна строка.
- Значения статусов, типов транспортов и событий — константы классов, не «магические» строки.
- Исключения: `Mailer\Support\MailerException` — базовое; `TransportException` различает
  временную и постоянную ошибку (`isTemporary()`), это влияет на ретраи.
- Ввод из HTTP всегда проходит `Validator`; в SQL — только подготовленные выражения.
- Каждый именованный параметр встречается в запросе **один раз**: MySQL работает с настоящими
  подготовленными выражениями (`PDO::ATTR_EMULATE_PREPARES => false`) и повтор имени не
  принимает. Нужно одно значение в нескольких условиях — заводите `:search_subject`,
  `:search_to` и так далее. Это проверяется тестом (`tests/SqlTest.php`).
- Логи — `var/log/mailer-YYYY-MM-DD.log`: время, уровень, канал, сообщение, контекст JSON.

## 10. Тесты

Свой раннер: `php bin/mailer test` (он же `php tests/run.php`). Покрыты MIME-сборка,
кодировки заголовков, DKIM, парсер сырых писем (sendmail/SMTP), валидация API, очередь с
backoff, rate limit, шаблоны, роутер. Транспорты в тестах — `null`/`log`, реальная сеть не
используется.

## 11. Эксплуатация

- Воркер — systemd (`deploy/mailer-worker.service`) либо cron (`php bin/mailer worker --once`).
  Перезапуск — кнопкой в панели или `worker:restart`: воркер выходит сам, systemd поднимает
  его заново (`Restart=always`).
- SMTP-релей — `deploy/mailer-smtpd.service`, слушает только `127.0.0.1`.
- nginx: `deploy/nginx.conf.example`, API и панель — разные `location`, на панели basic auth.
- Каталог `var/` должен быть доступен на запись пользователю php-fpm и воркера.
