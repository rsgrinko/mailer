<?php

declare(strict_types=1);

/**
 * Отписка одной кнопкой: токен, заголовки письма и публичная страница.
 */

use Mailer\Bounce\Unsubscribe;
use Mailer\Http\ApiKernel;
use Mailer\MailService;
use Mailer\Repository\MessageRepository;
use Mailer\Repository\ProjectRepository;
use Mailer\Repository\SuppressionRepository;
use Mailer\Repository\TransportRepository;
use Mailer\Support\Config;

/**
 * Проект с включённой отпиской и транспорт-заглушка.
 *
 * @return array<string, mixed>
 */
function unsubscribeFixtures(): array
{
    static $fixtures = null;

    if ($fixtures !== null) {
        return $fixtures;
    }

    $created = (new ProjectRepository())->create([
        'name'        => 'отписка-тест',
        'unsubscribe' => true,
    ]);

    $transports = new TransportRepository();
    if ($transports->findByName('отписка-null') === null) {
        $transports->create(['name' => 'отписка-null', 'type' => 'null', 'settings' => [], 'active' => 1]);
    }

    $fixtures = ['project' => (array) (new ProjectRepository())->find((int) $created['project']['id'])];

    return $fixtures;
}

/**
 * Настройки, при которых отписка вообще работает.
 */
function unsubscribeOn(): void
{
    Config::set('app.url', 'https://mail.example.com');
    Config::set('app.key', 'ключ-для-подписи-токенов');
    Config::set('unsubscribe.enabled', true);
}

function unsubscribeOff(): void
{
    Config::set('unsubscribe.enabled', false);
    Config::set('app.url', '');
}

test('токен отписки подписан и разбирается обратно', function (): void {
    unsubscribeOn();

    $token = Unsubscribe::token('Ivan@Example.com', 7);
    $data  = Unsubscribe::parse($token);

    assertTrue($data !== null, 'свой токен должен разбираться');
    assertSame('ivan@example.com', $data['email'], 'адрес приводится к нижнему регистру');
    assertSame(7, $data['project_id']);

    // Подделать адрес в токене не выйдет: подпись не сойдётся
    [$payload, $signature] = explode('.', $token);
    $fake = rtrim(strtr(base64_encode('{"e":"чужой@example.com","p":7,"t":' . time() . '}'), '+/', '-_'), '=');

    assertSame(null, Unsubscribe::parse($fake . '.' . $signature), 'чужие данные с чужой подписью не проходят');
    assertSame(null, Unsubscribe::parse('мусор'), 'мусор не проходит');

    unsubscribeOff();
});

test('без настроек ссылка отписки не собирается', function (): void {
    Config::set('unsubscribe.enabled', true);
    Config::set('app.url', '');

    assertSame('', Unsubscribe::url('ivan@example.com', 1), 'без внешнего адреса ссылку строить не из чего');
    assertSame([], Unsubscribe::headers('ivan@example.com', 1));

    unsubscribeOff();
});

test('заголовки отписки ставятся письму проекта, где она включена', function (): void {
    unsubscribeOn();

    $ids = unsubscribeFixtures();

    assertTrue(Unsubscribe::enabled($ids['project']), 'у проекта отписка включена');
    assertFalse(Unsubscribe::enabled(null), 'письму без проекта отписывать некого');

    $headers = Unsubscribe::headers('ivan@example.com', (int) $ids['project']['id']);

    assertContains('https://mail.example.com/unsubscribe/', $headers['List-Unsubscribe'] ?? '');
    assertSame('List-Unsubscribe=One-Click', $headers['List-Unsubscribe-Post'] ?? '');

    unsubscribeOff();
});

test('страница отписки закрывает адрес только по нажатию', function (): void {
    unsubscribeOn();

    $ids    = unsubscribeFixtures();
    $kernel = new ApiKernel();
    $token  = Unsubscribe::token('leaving@example.com', (int) $ids['project']['id']);
    $list   = new SuppressionRepository();

    // Почтовые клиенты открывают ссылки заранее — на GET отписывать нельзя
    $page = $kernel->handle(httpRequest('GET', '/unsubscribe/' . $token));

    assertSame(200, $page->status());
    assertContains('leaving@example.com', $page->body());
    assertFalse($list->isBlocked('leaving@example.com', (int) $ids['project']['id']), 'после GET адрес ещё открыт');

    // А по нажатию — закрываем
    $done = $kernel->handle(httpRequest('POST', '/unsubscribe/' . $token));

    assertSame(200, $done->status());
    assertContains('отписан', $done->body());
    assertTrue($list->isBlocked('leaving@example.com', (int) $ids['project']['id']), 'адрес закрыт для проекта');
    assertFalse($list->isBlocked('leaving@example.com'), 'другим проектам это не мешает');

    // Испорченная ссылка — понятное сообщение, а не пятисотая
    $broken = $kernel->handle(httpRequest('GET', '/unsubscribe/сломанный-токен'));
    assertSame(410, $broken->status());

    $list->unblock('leaving@example.com');
    unsubscribeOff();
});

test('отписавшийся больше не получает писем проекта', function (): void {
    unsubscribeOn();

    $ids  = unsubscribeFixtures();
    $list = new SuppressionRepository();
    $id   = $list->block('quiet@example.com', SuppressionRepository::UNSUBSCRIBE, 'unsubscribe', [
        'project_id' => (int) $ids['project']['id'],
    ]);

    $accepted = (new MailService())->accept([
        'to'        => 'quiet@example.com',
        'subject'   => 'Рассылка',
        'text'      => 'Текст',
        'transport' => 'отписка-null',
    ], $ids['project']);

    assertSame(MessageRepository::SUPPRESSED, (string) $accepted['status']);

    (new MessageRepository())->delete((int) $accepted['id']);
    $list->delete($id);
    unsubscribeOff();
});
