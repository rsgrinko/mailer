# Подключение приложений без SDK

Три способа отправлять письма через сервис, когда подключить PHP-SDK нельзя: чужой язык,
закрытая коробочная система или просто нежелание трогать рабочий код.

## 1. Подмена sendmail

Самый незаметный способ для приложений, которые уже умеют слать почту через `sendmail`
или функцию `mail()`. Скрипт `bin/mailer-sendmail` читает письмо со стандартного входа
и кладёт его в очередь.

### Настройка для PHP-приложений

В `php.ini` (или в конфиге пула php-fpm) укажите:

```ini
sendmail_path = /var/www/mailer/bin/mailer-sendmail -t -i
```

Для отдельного сайта — в `.user.ini` или в конфиге виртуального хоста:

```
php_admin_value[sendmail_path] = /var/www/mailer/bin/mailer-sendmail -t -i
```

После этого обычный код продолжает работать как раньше, а письма идут через сервис:

```php
mail('user@example.com', 'Тема', 'Тело письма');
```

### Подмена системного sendmail целиком

Если через `/usr/sbin/sendmail` шлют письма не только PHP-приложения:

```bash
mv /usr/sbin/sendmail /usr/sbin/sendmail.orig
ln -s /var/www/mailer/bin/mailer-sendmail /usr/sbin/sendmail
```

Скрипт понимает ключи настоящего sendmail: `-t` (получатели из заголовков), `-i`, `-f адрес`
(отправитель конверта), `-F имя`. Остальные принимает и игнорирует.

Коды выхода: `0` — письмо принято, `64` — ошибка в аргументах, `75` — временная ошибка
(почтовая система повторит попытку сама).

### Права

Скрипт пишет в базу и в `var/`, поэтому запускающий пользователь (обычно `www-data`)
должен иметь права на эти каталоги:

```bash
chmod +x /var/www/mailer/bin/mailer-sendmail
chown -R www-data:www-data /var/www/mailer/var
```

Письма, пришедшие таким путём, в панели помечены источником `sendmail` и относятся к проекту
`local-sendmail` (имя настраивается через `SENDMAIL_PROJECT`).

## 2. Локальный SMTP-релей

Подходит любому приложению на любом языке, у которого в настройках можно указать SMTP-сервер:
1С, Bitrix, Java, Python, Node.js, самописные скрипты.

Запуск:

```bash
php bin/mailer smtpd
```

или как служба — `deploy/mailer-smtpd.service`.

В настройках приложения указывается:

```
SMTP-сервер: 127.0.0.1
Порт:        2525
Шифрование:  нет
Логин:       не требуется (по умолчанию)
```

Релей слушает только локальный адрес и без шифрования — наружу его выставлять не нужно.
Если приложение всё же требует логин и пароль, включите проверку в `.env`:

```
SMTPD_AUTH_USER=app
SMTPD_AUTH_PASSWORD=длинный-пароль
```

Тогда релей потребует `AUTH LOGIN` или `AUTH PLAIN` с этими данными.

Письма из релея помечены источником `smtpd` и относятся к проекту `local-relay`
(имя настраивается через `SMTPD_PROJECT`).

Проверить работу можно так:

```bash
php -r '
require "bootstrap.php";
$c = new Mailer\Transport\SmtpClient(["host"=>"127.0.0.1","port"=>2525,"encryption"=>"none"]);
echo $c->send("from@example.com", ["to@example.com"],
    "From: from@example.com\r\nTo: to@example.com\r\nSubject: test\r\n\r\nпроверка\r\n");
$c->quit();
'
```

## 3. Утилита командной строки и curl

Для cron, CI и shell-скриптов есть `bin/mailer-send` — ему нужен только `curl`.

```bash
export MAILER_API=http://mail.internal
export MAILER_KEY=mlr_ваш_ключ

bin/mailer-send --to user@example.com --subject "Бэкап готов" --text "Всё прошло успешно"

# текст письма можно передать по конвейеру
tail -50 /var/log/backup.log | bin/mailer-send --to admin@example.com --subject "Лог бэкапа"

# с вложением и ожиданием результата
bin/mailer-send --to admin@example.com --subject "Отчёт" \
    --html /tmp/report.html --attach /tmp/report.pdf --sync
```

Ключи: `--to`, `--cc`, `--subject`, `--text`, `--html` (строка или путь к файлу), `--from`,
`--tag`, `--transport`, `--attach` (можно повторять), `--sync`, `--api`, `--key`.

Если утилиту скопировать некуда, тот же результат даёт обычный curl:

```bash
curl -X POST "$MAILER_API/api/v1/messages" \
  -H "Authorization: Bearer $MAILER_KEY" \
  -H "Content-Type: application/json" \
  -d '{"to":"user@example.com","subject":"Привет","text":"Тело письма"}'
```

Примеры на других языках:

```python
import json, urllib.request

data = json.dumps({"to": "user@example.com", "subject": "Привет", "text": "Тело"}).encode()
request = urllib.request.Request(
    "http://mail.internal/api/v1/messages",
    data=data,
    headers={"Authorization": "Bearer mlr_ключ", "Content-Type": "application/json"},
)
print(json.load(urllib.request.urlopen(request)))
```

```javascript
await fetch('http://mail.internal/api/v1/messages', {
    method: 'POST',
    headers: {
        'Authorization': 'Bearer mlr_ключ',
        'Content-Type': 'application/json',
    },
    body: JSON.stringify({ to: 'user@example.com', subject: 'Привет', text: 'Тело' }),
});
```

## 4. DokuWiki — плагин

Для вики на DokuWiki есть готовый плагин: `integrations/dokuwiki/mailerservice`.
Он перехватывает событие `MAIL_MESSAGE_SEND` и отдаёт сервису собранное вики письмо
целиком (полем `raw`), поэтому шаблоны, вложения и заголовки DokuWiki не меняются.

```bash
cp -r integrations/dokuwiki/mailerservice /var/www/dokuwiki/lib/plugins/
php bin/mailer key:create dokuwiki
```

Дальше в вики: **Управление → Настройки**, раздел «Плагин mailerservice» — адрес сервиса,
API-ключ, режим (`queue` или `sync`), транспорт, метка, запасная отправка. Проверка и
тестовое письмо — на странице **Управление → Сервис рассылки**.

Отправитель берётся из настройки вики `mailfrom`, он должен быть разрешён транспорту.
Подробности — в `integrations/dokuwiki/mailerservice/README.md`.

## 5. WordPress — плагин

Для сайтов на WordPress есть готовый плагин: `integrations/wordpress/mailerservice`.
Он перехватывает все письма через фильтр `pre_wp_mail` (WordPress 5.7+): регистрация,
сброс пароля, уведомления плагинов и формы уходят сервису по HTTP API. Сервис сам
собирает MIME, ставит DKIM и отвечает за доставку, поэтому менять тему или код
плагинов не нужно.

```bash
cp -r integrations/wordpress/mailerservice /var/www/site/wp-content/plugins/
php bin/mailer key:create wordpress
```

Дальше в админке WordPress: **Параметры → Сервис рассылки** — адрес сервиса,
API-ключ, режим (`queue` или `sync`), транспорт, метка, отправитель, запасная
отправка, журнал диагностики. Проверка и тестовое письмо — на той же странице.

### Отправитель

Плагин не подставляет `wordpress@домен-сайта` — этот адрес SMTP-транспорту
обычно не принадлежит, и письмо упадёт на отправке:

- задан **Отправитель (Email)** в настройках — письма идут с него;
- нет — берётся заголовок `From` письма;
- нет и его — поле `from` в запрос не передаётся, и отправителя подставляет
  транспорт сервиса (его собственный адрес разрешён по определению).

Транспорт Яндекса шлёт только со своих адресов: письмо с чужим `From` отклоняется
с `550 5.7.0 Sender address rejected: not owned by authorized user`. Поэтому либо
задайте в настройках адрес, принадлежащий аккаунту транспорта, либо оставьте
поле пустым — транспорт сам подставит свой адрес.

### Журнал диагностики

Если письма не попадают в очередь, на странице настроек есть раздел **Диагностика**:
версия WordPress и PHP, откуда определена `wp_mail` (штатная или переопределена),
активные плагины и перехватчики почты (`pre_wp_mail`, `phpmailer_init`),
журнал событий — что плагин сделал с каждым письмом (передан сервису, ошибка,
включился ли fallback). Журнал хранит последние 50 записей и работает без доступа
к логу сервера.

Плагин не сможет перехватить почту, если `wp_mail` переопределена до него — например,
mu-плагином (`mu-plugins/`): такие функции `function_exists` уже считает существующими,
а их версия может не вызывать фильтр `pre_wp_mail`. В этом случае диагноз виден сразу
в разделе «Диагностика» («wp_mail переопределена: …»), а лечится отключением или
правкой переопределяющего плагина.

Подробности — в `integrations/wordpress/mailerservice/README.md`.

## 6. Laravel — пакет

Для проектов на Laravel есть composer-пакет `rsgrinko/laravel-mailerservice-sdk`
(`integrations/laravel/`): почтовый транспорт для `config/mail.php` и клиент API
с фасадом `MailService`.

```bash
composer require rsgrinko/laravel-mailerservice-sdk
php bin/mailer key:create laravel
```

В `config/mail.php` добавляется мейлер с транспортом `mailerservice`, адрес сервиса
и ключ — в `.env` (`MAILERSERVICE_URL`, `MAILERSERVICE_KEY`). Дальше `Mail::to(...)->send(...)`
работает как раньше, а письма уходят сервису по HTTP API: тема, адреса, тела, вложения,
картинки в HTML, метка и метаданные раскладываются автоматически.

Проверить связь и отправить тестовое письмо можно не выходя из проекта:

```bash
php artisan mailerservice:test you@example.com
```

Команда идёт по шагам — настройки, доступность сервиса, письмо через API,
письмо через почтовый транспорт — и показывает, на каком именно всё встало.

Отправитель берётся из `MAIL_FROM_ADDRESS`, он должен принадлежать аккаунту
транспорта: чужой `From` Яндекс отвергает с `553 5.7.1 Sender address rejected`.

Подробности — в `integrations/laravel/README.md`.

## Имена транспортов и шаблонов

Везде, где в настройках интеграции указывается имя транспорта или шаблона, оно ищется
среди доступных владельцу проекта: свои плюс общие транспорты. Чужого имени для проекта
не существует — ответ будет `422` «не найден», как на опечатку. Проект, заведённый до
разделения прав, владельца не имеет, и для него по-прежнему доступно всё.

## Что выбрать

| Ситуация | Способ |
|----------|--------|
| Свой PHP-проект | SDK |
| Проект на Laravel | пакет `rsgrinko/laravel-mailerservice-sdk` |
| DokuWiki | плагин `mailerservice` |
| WordPress | плагин `mailerservice` |
| Старое PHP-приложение, код трогать нельзя | подмена sendmail |
| Приложение на другом языке с настройками SMTP | локальный релей |
| Скрипты, cron, CI | `bin/mailer-send` или curl |
| Сервис на другой машине | HTTP API напрямую |
