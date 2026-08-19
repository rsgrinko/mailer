# Сервис отправки почты

Небольшой самостоятельный сервис на PHP 8.1+ **без composer и внешних зависимостей**.
Принимает письма по HTTP API, складывает их в очередь и отправляет через SMTP (Яндекс и любой
другой), sendmail или в файлы. Есть веб-панель, мини-SDK для PHP-проектов и способы
подключить приложения, которые SDK использовать не могут.

## Что умеет

- **HTTP API** — приём писем, статусы, повторы, отмена, список, шаблоны, health-check.
- **Очередь с повторами** — временные ошибки SMTP не теряют письмо: следующая попытка через
  минуту, пять, пятнадцать и так далее.
- **Транспорты** — SMTP (SSL/STARTTLS, LOGIN/PLAIN/CRAM-MD5), sendmail, запись в файлы,
  заглушка, а также цепочка (failover) и чередование (round-robin) между несколькими.
- **Письма целиком** — HTML и текст, вложения, картинки внутри HTML, копии и скрытые копии,
  свои заголовки, шаблоны с переменными, DKIM-подпись.
- **Веб-панель** — очередь, история, содержимое писем, транспорты, проекты, ключи, шаблоны,
  вебхуки, логи и состояние сервиса. Управлять можно всем, в базу лезть не нужно.
  Вход по логину и паролю, пользователей может быть сколько угодно.
- **Проекты и лимиты** — у каждого клиента свой API-ключ, свои ограничения в час и в сутки,
  свой вебхук о результате отправки.
- **Работа без SDK** — подмена sendmail, локальный SMTP-релей на 127.0.0.1:2525 и shell-утилита.

## Быстрый старт

```bash
cp .env.example .env
php bin/mailer app:key          # ключ шифрования — вписать в .env
php bin/mailer migrate          # создать таблицы (по умолчанию SQLite в var/)
php bin/mailer seed             # транспорт из .env, шаблон, служебные проекты

php bin/mailer transport:test yandex          # проверить связь с почтой
php bin/mailer send:test user@example.com     # отправить тестовое письмо

php -S 127.0.0.1:8080 -t public public/index.php   # API и панель локально
php bin/mailer worker                              # воркер очереди
```

Панель: <http://127.0.0.1:8080/ui/>, API: <http://127.0.0.1:8080/api/v1>.
При первом открытии панель попросит завести пользователя (или заведите заранее:
`php bin/mailer user:create admin`).

## Отправка письма

Из PHP-проекта — через мини-SDK (один файл, `require_once` и всё):

```php
require_once '/путь/к/mailer/integrations/php-sdk/MailerClient.php';

use Mailer\Sdk\Client;
use Mailer\Sdk\Mail;

$mailer = new Client('http://mail.internal', 'mlr_ваш_ключ');

$mailer->send(
    Mail::to('user@example.com')
        ->subject('Заказ оформлен')
        ->html('<p>Спасибо за заказ!</p>')
        ->attachFile('/tmp/invoice.pdf')
);
```

Из чего угодно — обычным HTTP-запросом:

```bash
curl -X POST http://mail.internal/api/v1/messages \
  -H "Authorization: Bearer mlr_ваш_ключ" \
  -H "Content-Type: application/json" \
  -d '{"to":"user@example.com","subject":"Привет","text":"Тело письма"}'
```

Из старого приложения, которое переписывать не хочется — через подмену sendmail в `php.ini`:

```ini
sendmail_path = /var/www/mailer/bin/mailer-sendmail -t -i
```

Или через локальный SMTP-релей: в настройках приложения указывается `127.0.0.1:2525`.

Для DokuWiki есть готовый плагин: `integrations/dokuwiki/mailerservice` копируется в
`lib/plugins/` вики, после чего вся её почта уходит через сервис.

## Документация

- [docs/API.md](docs/API.md) — HTTP API: адреса, поля, ответы, ошибки.
- [docs/SDK.md](docs/SDK.md) — мини-SDK для PHP и примеры.
- [docs/INTEGRATIONS.md](docs/INTEGRATIONS.md) — подключение приложений без SDK.
- [docs/DEPLOY.md](docs/DEPLOY.md) — установка на сервер: nginx, systemd, MySQL.
- [docs/OPERATIONS.md](docs/OPERATIONS.md) — эксплуатация: панель, команды, разбор проблем.

## Требования

PHP 8.1+ с расширениями `pdo_sqlite` (или `pdo_mysql`), `openssl`, `mbstring`, `json`.
`curl` желателен, но не обязателен — есть запасной вариант на потоках.

## Структура

```
bin/           консольные утилиты: mailer, mailer-sendmail, mailer-send
config/        настройки (читают .env)
public/        единая точка входа: /api/v1 и /ui
integrations/  код для клиентских проектов: php-sdk, плагин DokuWiki
src/           код сервиса
tests/         тесты (php bin/mailer test)
deploy/        конфиги nginx и systemd
docs/          документация
var/           база, логи, вложения — в репозиторий не попадает
```
