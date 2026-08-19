<?php

declare(strict_types=1);

/**
 * Поиск по письмам: в MySQL — полнотекстовый индекс, в SQLite — перебор с LIKE.
 */

use Mailer\Repository\MessageRepository;

/**
 * Условие поиска, как его строит репозиторий: с полнотекстовым индексом и без.
 *
 * @return array{0: string, 1: array<string, mixed>}
 */
function searchCondition(string $driver, string $search): array
{
    return Mailer\Repository\MessageRepository::searchCondition($search, $driver === 'mysql');
}

test('в MySQL обычный поиск идёт через полнотекстовый индекс', function (): void {
    [$condition, $params] = searchCondition('mysql', 'заказ оформлен');

    assertContains('MATCH (subject, to_json, from_email)', $condition);
    assertContains('BOOLEAN MODE', $condition);
    assertSame('+заказ +оформлен*', $params['search_match'], 'все слова обязательны, последнее ищется по началу');
});

test('короткое слово и идентификатор ищутся перебором', function (): void {
    // Слова короче трёх символов полнотекстовый индекс не хранит
    [$condition] = searchCondition('mysql', 'об');
    assertContains('LIKE', $condition);

    // Идентификатор письма полнотекстовый индекс разорвал бы на куски
    [$condition] = searchCondition('mysql', '11badbcf-da34-430c-b591-aa02fd51b875');
    assertContains('LIKE', $condition);

    // И одно короткое слово в середине тоже переводит запрос на перебор
    [$condition] = searchCondition('mysql', 'заказ от 1с');
    assertContains('LIKE', $condition);
});

test('в SQLite поиск всегда перебором', function (): void {
    [$condition, $params] = searchCondition('sqlite', 'заказ оформлен');

    assertContains('subject LIKE :search_subject', $condition);
    assertSame('%заказ оформлен%', $params['search_subject']);
});

test('поиск находит письмо по теме и по адресу', function (): void {
    $messages = new MessageRepository();
    $message  = new Mailer\Message\Message();

    $message->to      = [new Mailer\Message\Address('searchable@example.com', 'Получатель поиска')];
    $message->from    = new Mailer\Message\Address('noreply@example.com');
    $message->subject = 'Уведомление о поисковом запросе';
    $message->text    = 'Текст';

    $messages->store($message);

    assertTrue($messages->paginate(['search' => 'поисковом'], 1, 10)['total'] >= 1, 'нашлось по теме');
    assertTrue($messages->paginate(['search' => 'searchable@example.com'], 1, 10)['total'] >= 1, 'нашлось по адресу');
    assertSame(0, $messages->paginate(['search' => 'такогословатутточнонет'], 1, 10)['total']);
});
