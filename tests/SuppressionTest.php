<?php

declare(strict_types=1);

/**
 * Стоп-лист: кому сервис не пишет и как адрес туда попадает.
 */

use Mailer\MailService;
use Mailer\Repository\EventRepository;
use Mailer\Repository\MessageRepository;
use Mailer\Repository\ProjectRepository;
use Mailer\Repository\SuppressionRepository;
use Mailer\Storage\Database;

/**
 * Проект и транспорт-заглушка, на которых гоняем стоп-лист.
 *
 * @return array<string, mixed>
 */
function suppressionFixtures(): array
{
    static $fixtures = null;

    if ($fixtures !== null) {
        return $fixtures;
    }

    $created = (new ProjectRepository())->create(['name' => 'стоп-лист-тест']);

    (new Mailer\Repository\TransportRepository())->create([
        'name'     => 'стоп-лист-null',
        'type'     => 'null',
        'settings' => [],
        'active'   => 1,
    ]);

    $fixtures = ['project' => $created['project'], 'key' => (string) $created['key']];

    return $fixtures;
}

test('закрытый адрес не попадает в письмо', function (): void {
    $ids   = suppressionFixtures();
    $list  = new SuppressionRepository();
    $block = $list->block('BLOCKED@Example.COM', SuppressionRepository::BOUNCE, 'cli');

    // Адрес приводится к нижнему регистру, иначе проверка его не найдёт
    assertTrue($list->isBlocked('blocked@example.com'), 'адрес должен быть закрыт');

    $accepted = (new MailService())->accept([
        'to'        => ['blocked@example.com', 'good@example.com'],
        'subject'   => 'Письмо мимо стоп-листа',
        'text'      => 'Текст',
        'transport' => 'стоп-лист-null',
    ], $ids['project']);

    $row = (array) (new MessageRepository())->find((int) $accepted['id']);

    assertSame(MessageRepository::QUEUED, (string) $row['status'], 'один получатель остался — письмо в очереди');
    assertContains('good@example.com', (string) $row['to_json']);
    assertNotContains('blocked@example.com', (string) $row['to_json'], 'закрытый адрес должен быть убран');

    $events = array_map(
        static fn (array $event): string => (string) $event['type'],
        (new EventRepository())->forMessage((int) $accepted['id'])
    );
    assertTrue(in_array(EventRepository::SUPPRESSED, $events, true), 'в истории должно быть событие о стоп-листе');

    (new MessageRepository())->delete((int) $accepted['id']);
    (new SuppressionRepository())->delete($block);
});

test('письмо всем закрытым получателям не уходит', function (): void {
    $ids  = suppressionFixtures();
    $list = new SuppressionRepository();
    $id   = $list->block('nobody@example.com');

    $accepted = (new MailService())->accept([
        'to'        => 'nobody@example.com',
        'subject'   => 'Письмо в никуда',
        'text'      => 'Текст',
        'transport' => 'стоп-лист-null',
    ], $ids['project']);

    assertSame(MessageRepository::SUPPRESSED, (string) $accepted['status']);

    $row = (array) (new MessageRepository())->find((int) $accepted['id']);
    assertSame(MessageRepository::SUPPRESSED, (string) $row['status'], 'письмо не должно ждать в очереди');

    (new MessageRepository())->delete((int) $accepted['id']);
    $list->delete($id);
});

test('синхронная отправка закрытому адресу отвечает по делу', function (): void {
    $ids  = suppressionFixtures();
    $list = new SuppressionRepository();
    $id   = $list->block('sync-nobody@example.com');

    // Раньше sync шёл захватывать письмо, которое уже никуда не поедет, и клиент
    // получал 502 с «Письмо уже обработано другим процессом»
    $accepted = (new MailService())->accept([
        'to'        => 'sync-nobody@example.com',
        'subject'   => 'Синхронно в никуда',
        'text'      => 'Текст',
        'transport' => 'стоп-лист-null',
        'sync'      => true,
    ], $ids['project']);

    assertSame(MessageRepository::SUPPRESSED, (string) $accepted['status']);
    assertTrue($accepted['sync'], 'ответ должен быть про синхронную отправку');
    assertContains('стоп-лист', (string) $accepted['info']);

    // И повторный запрос уже сохранённого письма отвечает тем же, а не «занято»
    $again = (new MailService())->sendNow((int) $accepted['id']);

    assertSame(MessageRepository::SUPPRESSED, $again['status']);
    assertContains('некому', $again['info']);

    // API такой ответ не считает ошибкой шлюза: сервис отработал как просили
    $response = (new Mailer\Http\ApiKernel())->handle(httpRequest(
        'POST',
        '/api/v1/messages',
        ['authorization' => 'Bearer ' . $ids['key']],
        [
            'to'        => 'sync-nobody@example.com',
            'subject'   => 'Синхронно в никуда, через API',
            'text'      => 'Текст',
            'transport' => 'стоп-лист-null',
            'sync'      => true,
        ]
    ));

    assertSame(200, $response->status());

    $answer = (array) json_decode($response->body(), true);
    assertSame(MessageRepository::SUPPRESSED, $answer['status']);

    $messages = new MessageRepository();
    $second   = $messages->findAny((string) $answer['id']);

    $messages->delete((int) $accepted['id']);
    if ($second !== null) {
        $messages->delete((int) $second['id']);
    }
    $list->delete($id);
});

test('запрет по проекту не задевает другие проекты', function (): void {
    $ids  = suppressionFixtures();
    $list = new SuppressionRepository();

    $id = $list->block('client@example.com', SuppressionRepository::UNSUBSCRIBE, 'api', [
        'project_id' => (int) $ids['project']['id'],
    ]);

    assertTrue($list->isBlocked('client@example.com', (int) $ids['project']['id']), 'для своего проекта закрыт');
    assertFalse($list->isBlocked('client@example.com'), 'для остальных адрес открыт');
    assertFalse($list->isBlocked('client@example.com', 999999), 'чужому проекту запрет не передаётся');

    $list->delete($id);
});

test('срок блокировки заканчивается сам', function (): void {
    $list = new SuppressionRepository();

    $id = $list->block('temporary@example.com', SuppressionRepository::BOUNCE, 'cli', [
        'expires_at' => date('Y-m-d H:i:s', time() - 60),
    ]);

    assertFalse($list->isBlocked('temporary@example.com'), 'просроченная запись адрес не закрывает');

    $list->delete($id);
});

test('повторная блокировка не плодит записи', function (): void {
    $list = new SuppressionRepository();
    $db   = Database::instance();

    $first  = $list->block('twice@example.com', SuppressionRepository::MANUAL, 'ui', ['note' => 'первый раз']);
    $second = $list->block('twice@example.com', SuppressionRepository::COMPLAINT, 'api', ['note' => 'второй раз']);

    assertSame($first, $second, 'адрес с тем же охватом должен обновляться, а не дублироваться');

    $row = (array) $db->selectOne('SELECT * FROM suppressions WHERE id = :id', ['id' => $first]);
    assertSame(SuppressionRepository::COMPLAINT, (string) $row['reason'], 'причина обновилась');
    assertSame('второй раз', (string) $row['note']);

    $list->delete($first);
});

test('API открывает свой адрес, но не общий', function (): void {
    $ids  = suppressionFixtures();
    $list = new SuppressionRepository();

    $own    = $list->block('own@example.com', SuppressionRepository::MANUAL, 'api', ['project_id' => (int) $ids['project']['id']]);
    $global = $list->block('global@example.com', SuppressionRepository::BOUNCE, 'bounce');

    assertSame(1, $list->unblock('own@example.com', (int) $ids['project']['id']), 'свою запись проект снимает');
    assertSame(0, $list->unblock('global@example.com', (int) $ids['project']['id']), 'общую — нет');

    assertTrue($list->isBlocked('global@example.com'), 'общая запись осталась');

    $list->delete($global);
    // Запись $own уже снята, но на всякий случай — вдруг unblock отработал иначе
    $list->delete($own);
});

test('панель показывает, кому письмо не ушло из-за стоп-листа', function (): void {
    Mailer\Support\Config::set('ui.auth', false);

    $ids  = suppressionFixtures();
    $list = new SuppressionRepository();
    $id   = $list->block('vidno@example.com');

    $accepted = (new MailService())->accept([
        'to'        => 'vidno@example.com',
        'subject'   => 'Кому — видно даже так',
        'text'      => 'Текст',
        'transport' => 'стоп-лист-null',
    ], $ids['project']);

    $kernel = new Mailer\Ui\UiKernel();

    // В самом письме получателей не осталось, но карточка и список берут их из истории.
    // Смотрим именно графу «Кому»: в истории событий адрес виден и без этого
    $card = $kernel->handle(httpRequest('GET', '/ui/messages/' . (int) $accepted['id']));

    assertSame(200, $card->status());

    $body  = $card->body();
    $start = strpos($body, '<dt>Кому</dt>');
    $end   = $start === false ? false : strpos($body, '</dd>', $start);
    $cell  = $start === false || $end === false ? '' : substr($body, $start, $end - $start);

    assertContains('vidno@example.com', $cell, 'в графе «Кому» должно быть видно, кому не ушло');

    $index = $kernel->handle(httpRequest('GET', '/ui/messages', [], [], ['search' => 'Кому — видно даже так']));

    assertSame(200, $index->status());
    assertContains('vidno@example.com', $index->body(), 'в списке тоже');

    (new MessageRepository())->delete((int) $accepted['id']);
    $list->delete($id);
});

test('в стоп-лист идёт отказ по ящику, а не любой 5xx', function (): void {
    // Ящика нет, домена нет, ящик отключён — адрес закрываем
    assertTrue(SuppressionRepository::isHardBounce('SMTP RCPT TO: сервер ответил 550 5.1.1 <a@b.ru>: Recipient address rejected: User unknown'));
    assertTrue(SuppressionRepository::isHardBounce('550 5.1.2 Host unknown'));
    assertTrue(SuppressionRepository::isHardBounce('550 5.2.1 mailbox disabled'));

    // А это про наш сервер и про временные беды — адрес ни при чём
    assertFalse(SuppressionRepository::isHardBounce('554 5.7.1 Relay access denied'));
    assertFalse(SuppressionRepository::isHardBounce('550 5.7.23 SPF check failed'));
    assertFalse(SuppressionRepository::isHardBounce('452 4.2.2 Mailbox full'));
    assertFalse(SuppressionRepository::isHardBounce('550 Requested action not taken'));
});
