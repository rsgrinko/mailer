# Установка на сервер

Пример для Debian/Ubuntu с nginx и php-fpm. Проект лежит в `/var/www/mailer`.

## 1. Файлы и права

```bash
cd /var/www
git clone <репозиторий> mailer      # либо просто скопировать каталог
cd mailer

cp .env.example .env
chown -R www-data:www-data var
chmod -R 775 var
chmod +x bin/mailer bin/mailer-sendmail bin/mailer-send
```

## 2. Настройки

Сгенерируйте ключ шифрования и впишите его в `.env`:

```bash
php bin/mailer app:key
```

Минимум, что стоит проверить в `.env`:

```
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:...
APP_HOSTNAME=example.com        # домен для Message-ID
LOG_LEVEL=info
LOG_SMTP=false
```

## 3. База данных

По умолчанию используется SQLite — файл `var/mailer.sqlite`, ничего настраивать не нужно:

```bash
php bin/mailer migrate
php bin/mailer seed
```

Для MySQL пропишите в `.env`:

```
DB_DRIVER=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=mailer
DB_USERNAME=mailer
DB_PASSWORD=пароль
```

и создайте базу:

```sql
CREATE DATABASE mailer CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'mailer'@'127.0.0.1' IDENTIFIED BY 'пароль';
GRANT ALL PRIVILEGES ON mailer.* TO 'mailer'@'127.0.0.1';
```

После этого — те же `migrate` и `seed`. Переключение драйвера не переносит данные:
если в SQLite уже что-то накопилось, переносите отдельно.

## 4. Транспорт

Через панель (**Транспорты → Добавить**) или командой:

```bash
php bin/mailer transport:add yandex --type=smtp \
    --host=smtp.yandex.ru --port=465 --encryption=ssl \
    --user=noreply@example.com --password='пароль приложения' \
    --from=noreply@example.com --from-name='Мой сервис' --default

php bin/mailer transport:test yandex
```

Для Яндекса нужен пароль приложения (не пароль от аккаунта) и разрешённый доступ по SMTP
в настройках почты. Порт 465 — SSL, 587 — STARTTLS (`--encryption=tls`).

Резервная цепочка на случай недоступности основного сервера:

```bash
php bin/mailer transport:add основная-цепочка --type=failover --transports=yandex,backup --default
```

## 5. nginx

Файл `deploy/nginx.conf.example` — рабочий пример. Главное:

- корень сайта — `/var/www/mailer/public`;
- все запросы уходят в `index.php`;
- на `/ui` вешается basic auth, потому что своей авторизации у панели нет.

```bash
htpasswd -c /etc/nginx/mailer.htpasswd admin
cp deploy/nginx.conf.example /etc/nginx/sites-available/mailer
ln -s /etc/nginx/sites-available/mailer /etc/nginx/sites-enabled/mailer
nginx -t && systemctl reload nginx
```

Панель и API лучше не выставлять в интернет без нужды: достаточно внутренней сети или
ограничения по IP.

## 6. Воркер

Без воркера письма будут копиться в очереди. Вариант с systemd:

```bash
cp deploy/mailer-worker.service /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now mailer-worker
systemctl status mailer-worker
```

Если systemd недоступен, подойдёт cron раз в минуту:

```
* * * * * www-data cd /var/www/mailer && php bin/mailer worker --once >> var/log/cron.log 2>&1
```

## 7. SMTP-релей (по желанию)

Нужен, если письма будут слать приложения, умеющие только SMTP:

```bash
cp deploy/mailer-smtpd.service /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now mailer-smtpd
```

## 8. Регулярное обслуживание

```
# чистка старых отправленных писем раз в сутки
30 4 * * * www-data cd /var/www/mailer && php bin/mailer queue:purge --status=sent --days=30
```

## 9. Проверка

```bash
php bin/mailer status                       # общее состояние
php bin/mailer send:test admin@example.com  # тестовое письмо
curl -s http://mail.internal/api/v1/health  # для мониторинга
```

В мониторинге удобно следить за `/api/v1/health`: `status` уходит в `degraded`, если воркер
перестал отзываться, и в `error`, если недоступна база.

## Обновление

```bash
cd /var/www/mailer
git pull
php bin/mailer migrate
systemctl restart mailer-worker mailer-smtpd
```

## Резервное копирование

- SQLite: файл `var/mailer.sqlite` (лучше останавливать воркер или копировать через
  `sqlite3 var/mailer.sqlite ".backup var/backup.sqlite"`);
- MySQL: обычный `mysqldump`;
- `.env` — в нём ключ шифрования: без него пароли транспортов из базы не прочитать;
- `var/spool` — вложения писем, ждущих отправки.
