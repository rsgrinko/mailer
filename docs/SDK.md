# Мини-SDK для PHP-проектов

SDK — один файл `sdk/MailerClient.php` без зависимостей. Скопируйте его к себе в проект
(или подключите по пути к сервису) и работайте.

```php
require '/путь/к/mailer/sdk/MailerClient.php';

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
| `health()` | состояние сервиса |

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

## Примеры

В каталоге `sdk/examples`:

- `basic.php` — простое письмо;
- `template.php` — письмо по шаблону;
- `attachments.php` — вложения и картинка внутри HTML;
- `bulk.php` — рассылка по списку с метками и приоритетом;
- `webhook-receiver.php` — приёмник вебхуков с проверкой подписи.
