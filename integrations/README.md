# Интеграции

Здесь лежит код, который ставится **на сторону клиента**, а не сервиса: его копируют
в чужой проект и подключают там. Сам сервис без этого каталога работает.

| Каталог | Что это |
|---------|---------|
| `php-sdk/` | мини-SDK для PHP-проектов: один файл `MailerClient.php` и примеры |
| `laravel/` | composer-пакет `rsgrinko/laravel-mailerservice-sdk`: почтовый транспорт и клиент API для Laravel |
| `dokuwiki/mailerservice/` | плагин DokuWiki: вся почта вики уходит через сервис |
| `wordpress/mailerservice/` | плагин WordPress: вся почта сайта (`wp_mail`) уходит через сервис |

Документация: [docs/SDK.md](../docs/SDK.md) и [docs/INTEGRATIONS.md](../docs/INTEGRATIONS.md).

Единственная связь с кодом сервиса — автозагрузчик `src/Autoload.php`: он подхватывает
`Mailer\Sdk\*` из `php-sdk/MailerClient.php`, чтобы SDK был доступен в примерах и тестах.
