# Мини-SDK для PHP-проектов

SDK — один файл `integrations/php-sdk/MailerClient.php` без зависимостей. Скопируйте его к себе в проект
(или подключите по пути к сервису) и работайте.

```php
require '/путь/к/mailer/integrations/php-sdk/MailerClient.php';

use Mailer\Sdk\Client;
use Mailer\Sdk\Mail;
use Mailer\Sdk\MailerException;
```

## Клиент

```php
$mailer = new Client(
    'http://mail.internal',   // адрес сервиса
    'mlr_ваш_ключ',           // API-ключ проекта
    15                        // таймаут запроса, секунды (необязательно)
);
```

Методы клиента:

| Метод | Что делает |
|-------|------------|
| `send($mail)` | ставит письмо в очередь |
| `sendNow($mail)` | отправляет сразу и ждёт результата |
| `status($id)` | состояние письма и его история |
| `messages($filters)` | список писем проекта |
| `retry($id)` | вернуть письмо в очередь |
| `cancel($id)` | отменить письмо |
| `templates()` | список шаблонов |
| `suppressions($filters)` | стоп-лист: кому проект больше не пишет |
| `suppress($email, $reason, $note)` | закрыть адрес |
| `unsuppress($email)` | открыть адрес обратно |
| `health()` | состояние сервиса |

Шаблоны и транспорты у ключа свои: видно то, что принадлежит владельцу проекта,
плюс общие транспорты. Чужого имени для проекта не существует.

## Письмо

Письмо собирается цепочкой вызовов:

```php
$mail = Mail::to('user@example.com')
    ->from('noreply@example.com', 'Интернет-магазин')
    ->cc(['manager@example.com'])
    ->replyTo('support@example.com')
    ->subject('Заказ №1024 оформлен')
    ->html('<p>Спасибо за заказ!</p>')
    ->text('Спасибо за заказ!')
    ->tag('заказы')
    ->meta(['order_id' => 1024]);

$result = $mailer->send($mail);
echo $result['id'];      // идентификатор письма
echo $result['status'];  // queued
```

Все методы письма:

| Метод | Назначение |
|-------|------------|
| `Mail::to($to)` | получатели: строка, «Имя &lt;адрес&gt;» или массив |
| `from($email, $name)` | отправитель |
| `cc($cc)`, `bcc($bcc)` | копии и скрытые копии |
| `replyTo($email)` | адрес для ответа |
| `subject($text)` | тема |
| `text($text)`, `html($html)` | тела письма |
| `template($name, $data)` | письмо по шаблону сервиса |
| `attach($name, $content, $type)` | вложение из данных в памяти |
| `attachFile($path, $name)` | вложение из файла |
| `inlineImage($cid, $path)` | картинка внутри HTML: `<img src="cid:логотип">` |
| `header($name, $value)` | свой заголовок письма |
| `tag($tag)` | метка для поиска в панели |
| `meta($array)` | данные, которые вернутся в вебхуке |
| `transport($name)` | отправить конкретным транспортом |
| `priority($number)` | приоритет в очереди (меньше — раньше) |
| `sendAt($when)` | отложенная отправка |
| `idempotencyKey($key)` | защита от дублей |
| `sync()` | дождаться отправки |

## Обработка ошибок

```php
try {
    $mailer->send(Mail::to('user@example.com')->subject('Привет')->text('Тело'));
} catch (MailerException $e) {
    // $e->getMessage() — что не так
    // $e->getCode()    — код ответа сервиса (401, 422, 429 …)
    // $e->errors       — список ошибок валидации
    error_log('Письмо не ушло: ' . $e->getMessage());
}
```

Сервис устроен так, что письмо принимается в очередь и отправляется потом. Поэтому обычный
`send()` возвращается быстро и не зависит от того, отвечает ли почтовый сервер.

У `sendNow()` (и `send()` с `sync()`) ошибка отправки — отдельный случай: письмо приняли
и сохранили, а почтовый сервер его не взял. Сервис отвечает `502`, в сообщении исключения
будет причина от транспорта, а весь ответ с идентификатором письма лежит в `$e->response`:

```php
try {
    $mailer->sendNow($mail);
} catch (MailerException $e) {
    if ($e->getCode() === 502) {
        // письмо в базе есть, отправить не вышло — можно повторить позже
        $mailer->retry($e->response['id']);
    }
}
```

## Шаблоны

```php
$mailer->send(
    Mail::to($user['email'])
        ->template('welcome', [
            'name' => $user['name'],
            'site' => 'example.com',
        ])
);
```

Тема и тело возьмутся из шаблона, а если задать `subject()` или `html()` явно — они
перекроют шаблон.

## Проверка статуса

```php
$result = $mailer->send($mail);

// позже
$status = $mailer->status($result['id']);

if ($status['message']['status'] === 'failed') {
    echo 'Не отправилось: ' . $status['message']['error'];
    $mailer->retry($result['id']);
}
```

## Стоп-лист

Адресам из стоп-листа сервис не пишет. Письмо такому получателю отсеивается на приёме,
а если закрыты все получатели, письмо принимается со статусом `suppressed`.

```php
$mailer->suppress('ivan@example.com', 'complaint', 'пожаловался на спам');

foreach ($mailer->suppressions(['reason' => 'bounce'])['items'] as $row) {
    echo $row['email'] . ' — ' . $row['reason'] . PHP_EOL;
}

$mailer->unsuppress('ivan@example.com');
```

Причины: `bounce`, `complaint`, `unsubscribe`, `manual`. Ключ закрывает адрес только для
своего проекта. Адрес, закрытый для всех проектов сразу (так ложатся отказы почтовых
серверов), через API не снять — только в панели, ответ будет `403`.

## Примеры

В каталоге `integrations/php-sdk/examples`:

- `basic.php` — простое письмо;
- `template.php` — письмо по шаблону;
- `attachments.php` — вложения и картинка внутри HTML;
- `bulk.php` — рассылка по списку с метками и приоритетом;
- `suppressions.php` — стоп-лист: закрыть адрес, посмотреть список, открыть обратно;
- `webhook-receiver.php` — приёмник вебхуков: проверка подписи со временем, разбор
  конверта и идемпотентность по идентификатору доставки.
