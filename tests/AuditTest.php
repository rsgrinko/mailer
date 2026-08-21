<?php

declare(strict_types=1);

/**
 * Журнал действий панели: что записывается, как ищется и когда чистится.
 */

use Mailer\Repository\AuditRepository;
use Mailer\Storage\Database;
use Mailer\Support\Config;
use Mailer\Ui\Audit;
use Mailer\Ui\UiKernel;

test('действие панели попадает в журнал и находится фильтрами', function (): void {
    $audit = new AuditRepository();

    // Номера пользователей заведомо чужие: с обычными 1-2-3 в фильтр попали бы
    // записи соседних тестов
    $first  = $audit->log(770001, 'ivan', AuditRepository::CREATED, 'project', 42, 'проект «журнал-тест»', '10.0.0.1');
    $second = $audit->log(770002, 'petr', AuditRepository::DELETED, 'template', 43, 'шаблон «журнал-тест-2»', '10.0.0.2');

    $byUser = $audit->paginate(['user_id' => 770001]);
    assertSame(1, $byUser['total'], 'по пользователю должна найтись одна запись');
    assertSame('project', (string) $byUser['items'][0]['entity']);

    // Журнал общий на все тесты, поэтому по разделу и действию проверяем не число
    // записей, а что наша среди них нашлась и чужие в фильтр не пролезли
    $byEntity = $audit->paginate(['entity' => 'template', 'action' => AuditRepository::DELETED]);
    assertTrue($byEntity['total'] >= 1, 'по разделу и действию запись не нашлась');

    $found = false;

    foreach ($byEntity['items'] as $item) {
        assertSame('template', (string) $item['entity'], 'в выдачу попал чужой раздел');
        assertSame(AuditRepository::DELETED, (string) $item['action'], 'в выдачу попало чужое действие');

        $found = $found || (int) $item['id'] === $second;
    }

    assertTrue($found, 'своя запись должна быть в выдаче');

    $bySearch = $audit->paginate(['search' => 'журнал-тест-2']);
    assertSame(1, $bySearch['total'], 'поиск идёт по описанию');

    assertSame(1, count($audit->forEntity('project', 42)), 'история одной записи');

    Database::instance()->delete('audit_log', ['id' => $first]);
    Database::instance()->delete('audit_log', ['id' => $second]);
});

test('помощник Audit подписывает запись тем, кто вошёл', function (): void {
    // Авторизация выключена — смотрит «полный» зритель без логина
    Config::set('ui.auth', false);

    Audit::action('system', null, 'проверка журнала');

    $page = (new AuditRepository())->paginate(['search' => 'проверка журнала']);

    assertSame(1, $page['total'], 'запись должна появиться');
    assertSame('action', (string) $page['items'][0]['action']);
    assertSame(null, $page['items'][0]['entity_id']);

    Database::instance()->delete('audit_log', ['id' => (int) $page['items'][0]['id']]);
});

test('страница журнала показывает записи', function (): void {
    Config::set('ui.auth', false);

    $id = (new AuditRepository())->log(0, 'проверка', AuditRepository::UPDATED, 'transport', 5, 'транспорт «журнал-страница»');

    $response = (new UiKernel())->handle(httpRequest('GET', '/ui/audit'));

    assertSame(200, $response->status());
    assertContains('журнал-страница', $response->body());
    assertContains('изменение', $response->body());

    Database::instance()->delete('audit_log', ['id' => $id]);
});

test('старые записи журнала чистятся по сроку', function (): void {
    $db = Database::instance();

    $old = $db->insert('audit_log', [
        'user_id'    => 0,
        'user_login' => 'старая',
        'action'     => AuditRepository::ACTION,
        'entity'     => 'system',
        'entity_id'  => null,
        'summary'    => 'запись из прошлого',
        'ip'         => '',
        'created_at' => date('Y-m-d H:i:s', strtotime('-400 days')),
    ]);

    $fresh = (new AuditRepository())->log(0, 'свежая', AuditRepository::ACTION, 'system', null, 'запись из настоящего');

    $removed = (new AuditRepository())->purge(180);

    assertTrue($removed >= 1, 'старая запись должна удалиться');
    assertSame(null, $db->selectOne('SELECT id FROM audit_log WHERE id = :id', ['id' => $old]));
    assertTrue($db->selectOne('SELECT id FROM audit_log WHERE id = :id', ['id' => $fresh]) !== null, 'свежая остаётся');

    $db->delete('audit_log', ['id' => $fresh]);
});
