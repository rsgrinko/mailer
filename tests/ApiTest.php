<?php

declare(strict_types=1);

/**
 * Контракт API v1 — то, что видит чужой код.
 *
 * HttpTest обходит адреса и смотрит на коды ответов; здесь проверяется само
 * содержимое: поля карточки письма, страницы и фильтры списка, вложения,
 * идемпотентность, лимиты и форма ошибок. Поменяли ответ — тест об этом скажет,
 * потому что на другом конце его уже кто-то разбирает.
 */

use Mailer\Http\ApiKernel;
use Mailer\Repository\MessageRepository;
use Mailer\Repository\ProjectRepository;
use Mailer\Repository\TemplateRepository;
use Mailer\Repository\TransportRepository;
use Mailer\Storage\Database;

/**
 * Проект с ключом, транспорт-заглушка и шаблон в своей базе.
 *
 * @return array{key: string, project: array<string, mixed>}
 */
function apiFixtures(): array
{
    (new TransportRepository())->create([
        'name'       => 'апи-null',
        'type'       => 'null',
        'settings'   => [],
        'from_email' => 'noreply@example.com',
        'is_default' => true,
    ]);

    (new TemplateRepository())->create([
        'name'    => 'апи-шаблон',
        'subject' => 'Заказ {{ number }}',
        'text'    => 'Заказ {{ number }} принят',
    ]);

    $created = (new ProjectRepository())->create(['name' => 'апи-проект']);

    return ['key' => $created['key'], 'project' => $created['project']];
}

/**
 * Запрос к API с ключом проекта.
 *
 * @param  array<string, mixed> $body
 * @param  array<string, mixed> $query
 * @return array{status: int, json: array<string, mixed>}
 */
function apiCall(string $key, string $method, string $path, array $body = [], array $query = [], array $headers = []): array
{
    $response = (new ApiKernel())->handle(httpRequest(
        $method,
        $path,
        array_merge(['authorization' => 'Bearer ' . $key], $headers),
        $body,
        $query
    ));

    $json = json_decode($response->body(), true);

    return ['status' => $response->status(), 'json' => is_array($json) ? $json : []];
}

test('карточка письма отдаёт разобранные поля и историю', function (): void {
    withOwnDatabase(static function (): void {
        $ids = apiFixtures();

        $created = apiCall($ids['key'], 'POST', '/api/v1/messages', [
            'to'      => ['Иван <ivan@example.com>', 'petr@example.com'],
            'subject' => 'Карточка письма',
            'text'    => 'Текст',
            'tag'     => 'заказы',
            'sync'    => true,
        ]);

        assertSame(200, $created['status'], 'синхронная отправка отвечает 200');

        $uuid = (string) $created['json']['id'];

        assertMatches('/^[0-9a-f-]{36}$/', $uuid, 'идентификатор письма — uuid');

        $card = apiCall($ids['key'], 'GET', '/api/v1/messages/' . $uuid);

        assertSame(200, $card['status']);

        $message = $card['json']['message'];

        assertSame($uuid, $message['id']);
        assertSame('sent', $message['status']);
        assertSame('Карточка письма', $message['subject']);
        assertSame(['ivan@example.com', 'petr@example.com'], $message['to'], 'адреса отдаются списком');
        assertSame('заказы', $message['tag']);
        assertSame('апи-null', $message['transport']);
        assertNull($message['error'], 'у отправленного письма ошибки нет');
        assertTrue(isset($message['created_at'], $message['sent_at']), 'времена должны быть в ответе');

        $types = array_column($card['json']['events'], 'type');

        assertTrue(in_array('accepted', $types, true), 'в истории должно быть принятие');
        assertTrue(in_array('sent', $types, true), 'и отправка');
    });
});

test('список писем листается и фильтруется', function (): void {
    withOwnDatabase(static function (): void {
        $ids = apiFixtures();

        foreach (['Первое', 'Второе', 'Третье'] as $index => $subject) {
            apiCall($ids['key'], 'POST', '/api/v1/messages', [
                'to'      => 'user@example.com',
                'subject' => $subject,
                'text'    => 'текст',
                'tag'     => $index === 0 ? 'важное' : 'обычное',
            ]);
        }

        $page = apiCall($ids['key'], 'GET', '/api/v1/messages', [], ['per_page' => 2]);

        assertSame(200, $page['status']);
        assertSame(3, $page['json']['total'], 'всего писем проекта');
        assertSame(2, $page['json']['pages'], 'по два на страницу — две страницы');
        assertCount(2, $page['json']['items']);

        $second = apiCall($ids['key'], 'GET', '/api/v1/messages', [], ['per_page' => 2, 'page' => 2]);

        assertSame(2, $second['json']['page']);
        assertCount(1, $second['json']['items'], 'на второй странице остаток');

        $byTag = apiCall($ids['key'], 'GET', '/api/v1/messages', [], ['tag' => 'важное']);

        assertSame(1, $byTag['json']['total'], 'фильтр по метке');
        assertSame('Первое', $byTag['json']['items'][0]['subject']);

        $byStatus = apiCall($ids['key'], 'GET', '/api/v1/messages', [], ['status' => 'queued']);

        assertSame(3, $byStatus['json']['total'], 'все письма ещё в очереди');

        $bySearch = apiCall($ids['key'], 'GET', '/api/v1/messages', [], ['search' => 'Второе']);

        assertSame(1, $bySearch['json']['total'], 'поиск по теме');
    });
});

test('чужое письмо через ключ проекта не достать', function (): void {
    withOwnDatabase(static function (): void {
        $first  = apiFixtures();
        $second = (new ProjectRepository())->create(['name' => 'апи-сосед']);

        $created = apiCall($first['key'], 'POST', '/api/v1/messages', [
            'to'      => 'user@example.com',
            'subject' => 'Не для соседа',
            'text'    => 'текст',
        ]);

        $uuid = (string) $created['json']['id'];

        $foreign = apiCall($second['key'], 'GET', '/api/v1/messages/' . $uuid);

        assertSame(404, $foreign['status'], 'чужое письмо для проекта не существует');

        $list = apiCall($second['key'], 'GET', '/api/v1/messages');

        assertSame(0, $list['json']['total'], 'и в списке его нет');
    });
});

test('вложение доезжает до письма и ложится в spool', function (): void {
    withOwnDatabase(static function (): void {
        $ids = apiFixtures();

        $created = apiCall($ids['key'], 'POST', '/api/v1/messages', [
            'to'          => 'user@example.com',
            'subject'     => 'Письмо с вложением',
            'text'        => 'Смотрите вложение',
            'attachments' => [
                [
                    'name'         => 'отчёт.txt',
                    'content'      => base64_encode('строка отчёта'),
                    'content_type' => 'text/plain',
                ],
            ],
        ]);

        assertSame(202, $created['status'], $created['json']['error']['message'] ?? '');

        $row = (array) (new MessageRepository())->findAny((string) $created['json']['id']);

        assertContains('отчёт.txt', (string) $row['attachments_json'], 'вложение должно быть в метаданных');

        $stored = (array) json_decode((string) $row['attachments_json'], true);
        $path   = (string) ($stored[0]['path'] ?? '');

        assertTrue($path !== '' && is_file($path), 'файл вложения должен лежать в spool');
        assertSame('строка отчёта', (string) file_get_contents($path));

        // Удаление письма уносит и файл
        (new MessageRepository())->delete((int) $row['id']);

        assertFalse(is_file($path), 'файл вложения должен удалиться вместе с письмом');
    });
});

test('битое вложение не принимается', function (): void {
    withOwnDatabase(static function (): void {
        $ids = apiFixtures();

        $result = apiCall($ids['key'], 'POST', '/api/v1/messages', [
            'to'          => 'user@example.com',
            'subject'     => 'Битое вложение',
            'text'        => 'текст',
            'attachments' => [['name' => 'файл.txt', 'content' => 'это не base64 %%%']],
        ]);

        assertSame(422, $result['status']);
        assertContains('base64', json_encode($result['json'], JSON_UNESCAPED_UNICODE) ?: '');
    });
});

test('ключ идемпотентности принимается заголовком', function (): void {
    withOwnDatabase(static function (): void {
        $ids = apiFixtures();

        $payload = ['to' => 'user@example.com', 'subject' => 'Один раз', 'text' => 'текст'];
        $headers = ['idempotency-key' => 'заказ-42'];

        $first  = apiCall($ids['key'], 'POST', '/api/v1/messages', $payload, [], $headers);
        $second = apiCall($ids['key'], 'POST', '/api/v1/messages', $payload, [], $headers);

        assertSame(202, $first['status'], 'первое письмо принимается');
        assertSame(200, $second['status'], 'повтор отвечает 200, а не 202');
        assertSame($first['json']['id'], $second['json']['id'], 'идентификатор тот же');
        assertTrue((bool) ($second['json']['duplicate'] ?? false), 'повтор помечен как дубль');

        assertSame(1, Database::instance()->count('messages'), 'письмо должно быть одно');
    });
});

test('превышенный лимит проекта отвечает 429', function (): void {
    withOwnDatabase(static function (): void {
        apiFixtures();

        $created = (new ProjectRepository())->create(['name' => 'апи-лимит', 'rate_limit_hour' => 1]);
        $payload = ['to' => 'user@example.com', 'subject' => 'Лимит', 'text' => 'текст'];

        assertSame(202, apiCall($created['key'], 'POST', '/api/v1/messages', $payload)['status']);

        $second = apiCall($created['key'], 'POST', '/api/v1/messages', $payload);

        assertSame(429, $second['status'], 'на лимите положено отвечать 429');
        assertContains('лимит', mb_strtolower((string) $second['json']['error']['message']));
    });
});

test('отложенное письмо принимается с датой отправки', function (): void {
    withOwnDatabase(static function (): void {
        $ids  = apiFixtures();
        $when = date('Y-m-d H:i:s', time() + 3600);

        $created = apiCall($ids['key'], 'POST', '/api/v1/messages', [
            'to'      => 'later@example.com',
            'subject' => 'На потом',
            'text'    => 'текст',
            'send_at' => $when,
        ]);

        assertSame(202, $created['status']);

        $row = (array) (new MessageRepository())->findAny((string) $created['json']['id']);

        assertSame('queued', (string) $row['status']);
        assertSame($when, (string) $row['available_at'], 'время отправки должно сохраниться');
    });
});

test('письмо по шаблону собирается на стороне сервиса', function (): void {
    withOwnDatabase(static function (): void {
        $ids = apiFixtures();

        $created = apiCall($ids['key'], 'POST', '/api/v1/messages', [
            'to'            => 'user@example.com',
            'template'      => 'апи-шаблон',
            'template_data' => ['number' => 'А-100'],
        ]);

        assertSame(202, $created['status'], json_encode($created['json'], JSON_UNESCAPED_UNICODE) ?: '');

        $row = (array) (new MessageRepository())->findAny((string) $created['json']['id']);

        assertSame('Заказ А-100', (string) $row['subject'], 'тема из шаблона с подстановкой');
        assertContains('А-100', (string) $row['text_body']);
    });
});

test('несуществующий шаблон — понятная ошибка, а не 500', function (): void {
    withOwnDatabase(static function (): void {
        $ids = apiFixtures();

        $result = apiCall($ids['key'], 'POST', '/api/v1/messages', [
            'to'       => 'user@example.com',
            'template' => 'нет-такого-шаблона',
        ]);

        assertTrue($result['status'] === 404 || $result['status'] === 422, 'ожидался 404 или 422, получен ' . $result['status']);
        assertContains('шаблон', mb_strtolower(json_encode($result['json'], JSON_UNESCAPED_UNICODE) ?: ''));
    });
});

test('повтор и отмена письма через API', function (): void {
    withOwnDatabase(static function (): void {
        $ids = apiFixtures();

        $created = apiCall($ids['key'], 'POST', '/api/v1/messages', [
            'to'      => 'user@example.com',
            'subject' => 'Отменяемое',
            'text'    => 'текст',
        ]);

        $uuid = (string) $created['json']['id'];

        $canceled = apiCall($ids['key'], 'DELETE', '/api/v1/messages/' . $uuid);

        assertSame(200, $canceled['status']);
        assertSame(
            'canceled',
            (string) ((array) (new MessageRepository())->findAny($uuid))['status'],
            'письмо должно отмениться'
        );

        // Повтор возвращает его в очередь
        assertSame(200, apiCall($ids['key'], 'POST', '/api/v1/messages/' . $uuid . '/retry')['status']);
        assertSame('queued', (string) ((array) (new MessageRepository())->findAny($uuid))['status']);

        // Чужой идентификатор — 404, а не 500
        assertSame(404, apiCall($ids['key'], 'POST', '/api/v1/messages/нет-такого/retry')['status']);
        assertSame(404, apiCall($ids['key'], 'DELETE', '/api/v1/messages/нет-такого')['status']);
    });
});

test('ошибки API приходят единым конвертом', function (): void {
    withOwnDatabase(static function (): void {
        $ids = apiFixtures();

        // Без получателя — 422 со списком ошибок
        $invalid = apiCall($ids['key'], 'POST', '/api/v1/messages', ['subject' => 'Без адресата']);

        assertSame(422, $invalid['status']);
        assertTrue(isset($invalid['json']['error']['message']), 'ошибка приходит в поле error.message');
        assertTrue(isset($invalid['json']['error']['details']['errors']), 'а подробности — списком в details');

        // Пустое тело
        $empty = apiCall($ids['key'], 'POST', '/api/v1/messages');

        assertSame(422, $empty['status']);
        assertContains('Пустое тело', (string) $empty['json']['error']['message']);

        // Неизвестный адрес и неверный метод
        $kernel = new ApiKernel();

        assertSame(404, $kernel->handle(httpRequest('GET', '/api/v1/нет-такого'))->status());
        assertSame(405, $kernel->handle(httpRequest('PUT', '/api/v1/messages'))->status());
    });
});

test('без ключа и с чужим ключом в API не пускают', function (): void {
    withOwnDatabase(static function (): void {
        apiFixtures();

        $kernel = new ApiKernel();

        foreach ([[], ['authorization' => 'Bearer mlr_нет_такого'], ['authorization' => 'кривой заголовок']] as $headers) {
            $response = $kernel->handle(httpRequest('GET', '/api/v1/messages', $headers));

            assertSame(401, $response->status(), 'без верного ключа положено 401');
        }

        // Отозванный ключ тоже не работает
        $created = (new ProjectRepository())->create(['name' => 'апи-отозванный']);
        (new ProjectRepository())->update((int) $created['project']['id'], ['active' => false]);

        // Выключенный проект — это уже не «кто ты», а «тебе нельзя»: 403
        assertSame(403, apiCall($created['key'], 'GET', '/api/v1/messages')['status'], 'выключенный проект не пускаем');
    });
});

test('health отвечает без ключа и показывает состояние', function (): void {
    withOwnDatabase(static function (): void {
        apiFixtures();

        $response = (new ApiKernel())->handle(httpRequest('GET', '/api/v1/health'));
        $json     = (array) json_decode($response->body(), true);

        assertSame(200, $response->status(), 'пока база жива, health отвечает 200');

        // Воркер в тестах не запущен, поэтому общий статус — degraded: сервис принимает
        // письма, но очередь никто не разгребает. Это и должно быть видно снаружи
        assertSame('degraded', $json['status'] ?? '', 'без воркера состояние неполное');
        assertTrue(($json['checks']['database']['ok'] ?? false) === true, 'база должна быть в порядке');
        assertTrue(isset($json['checks']['queue']), 'в ответе должно быть состояние очереди');
        assertTrue(($json['checks']['worker']['ok'] ?? true) === false, 'и честная отметка про воркер');
    });
});
