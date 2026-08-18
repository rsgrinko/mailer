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

## Что выбрать

| Ситуация | Способ |
|----------|--------|
| Свой PHP-проект | SDK |
| Старое PHP-приложение, код трогать нельзя | подмена sendmail |
| Приложение на другом языке с настройками SMTP | локальный релей |
| Скрипты, cron, CI | `bin/mailer-send` или curl |
| Сервис на другой машине | HTTP API напрямую |
