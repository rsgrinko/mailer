<?php

declare(strict_types=1);

/**
 * Формы панели: раньше тесты ходили по страницам только на чтение, и всё, что
 * происходит по нажатию «Сохранить» — разбор полей, запись в базу, строка аудита,
 * сообщение об ошибке — не проверялось вовсе.
 *
 * Ходим настоящим ядром: с токеном, через прослойки, от лица администратора.
 */

use Mailer\Repository\AuditRepository;
use Mailer\Repository\ProjectRepository;
use Mailer\Repository\RoleRepository;
use Mailer\Repository\SuppressionRepository;
use Mailer\Repository\TemplateRepository;
use Mailer\Repository\TransportRepository;
use Mailer\Repository\UserRepository;
use Mailer\Storage\Database;
use Mailer\Ui\Csrf;
use Mailer\Ui\UiKernel;

/**
 * Администратор, от лица которого нажимаются кнопки.
 */
function uiWriteAdmin(): int
{
    static $id = null;

    $roles = new RoleRepository();
    $users = new UserRepository();

    // Соседний тест сносит всех пользователей разом, поэтому мало помнить номер —
    // надо убедиться, что он ещё чей-то
    if ($id !== null && $users->find($id) !== null) {
        return $id;
    }

    $existing = $users->findByLogin('ui-write');

    $id = $existing !== null
        ? (int) $existing['id']
        : (int) $users->create([
            'login'    => 'ui-write',
            'password' => 'parol123',
            'name'     => 'Кнопконажиматель',
            'role_id'  => (int) ((array) $roles->admin())['id'],
        ])['id'];

    static $cleanup = false;

    if (!$cleanup) {
        $cleanup = true;

        afterTests(static function (): void {
            $users = new UserRepository();
            $row   = $users->findByLogin('ui-write');

            if ($row !== null) {
                $users->delete((int) $row['id'], true);
            }
        });
    }

    return $id;
}

/**
 * POST в панель от администратора: токен подставляется сам.
 *
 * @param array<string, mixed> $body
 */
function uiPost(string $path, array $body = []): Mailer\Http\Response
{
    accessLogin(uiWriteAdmin());

    $response = (new UiKernel())->handle(
        httpRequest('POST', $path, ['x-csrf-token' => Csrf::token()], $body)
    );

    accessLogout();

    return $response;
}

/**
 * Строка стоп-листа по адресу: репозиторий ищет по id, а форме известен только адрес.
 *
 * @return array<string, mixed>|null
 */
function suppressionByEmail(string $email): ?array
{
    return Database::instance()->selectOne(
        'SELECT * FROM suppressions WHERE email = :email',
        ['email' => $email]
    );
}

/**
 * Последняя запись журнала по разделу — проверяем, что действие в него попало.
 *
 * @return array<string, mixed>|null
 */
function lastAudit(string $entity): ?array
{
    return Database::instance()->selectOne(
        'SELECT * FROM audit_log WHERE entity = :entity ORDER BY id DESC LIMIT 1',
        ['entity' => $entity]
    );
}

test('проект заводится, правится и удаляется через форму', function (): void {
    withConfig(['ui.auth' => true], static function (): void {
        $projects = new ProjectRepository();

        $response = uiPost('/ui/projects/save', [
            'name'            => 'форма-проект',
            'description'     => 'заведён из панели',
            'rate_limit_hour' => 100,
            'active'          => 'on',
        ]);

        assertSame(302, $response->status(), 'после сохранения должен быть переход');

        $project = assertNotNull($projects->findByName('форма-проект'), 'проект не сохранился');
        $id      = (int) $project['id'];

        assertSame(100, (int) $project['rate_limit_hour'], 'лимит не записался');
        assertSame(1, (int) $project['active']);

        $audit = assertNotNull(lastAudit('project'), 'действие не попало в журнал');
        assertSame('created', (string) $audit['action']);
        assertSame($id, (int) $audit['entity_id']);

        // Правка
        assertSame(302, uiPost('/ui/projects/save', [
            'id'              => $id,
            'name'            => 'форма-проект',
            'rate_limit_hour' => 250,
        ])->status());

        $project = (array) $projects->find($id);
        assertSame(250, (int) $project['rate_limit_hour'], 'правка не сохранилась');
        assertSame(0, (int) $project['active'], 'снятая галочка должна выключать проект');
        assertSame('updated', (string) assertNotNull(lastAudit('project'))['action']);

        // Удаление
        assertSame(302, uiPost('/ui/projects/' . $id . '/delete')->status());
        assertNull($projects->find($id), 'проект должен удалиться');
        assertSame('deleted', (string) assertNotNull(lastAudit('project'))['action']);
    });
});

test('проект без имени не сохраняется', function (): void {
    withConfig(['ui.auth' => true], static function (): void {
        $before = Database::instance()->count('projects');

        $response = uiPost('/ui/projects/save', ['name' => '']);

        assertSame(302, $response->status(), 'форма возвращает на страницу с ошибкой');
        assertSame($before, Database::instance()->count('projects'), 'проект без имени не должен появиться');
    });
});

test('транспорт заводится и правится через форму', function (): void {
    withConfig(['ui.auth' => true], static function (): void {
        $transports = new TransportRepository();

        assertSame(302, uiPost('/ui/transports/save', [
            'name'       => 'форма-транспорт',
            'type'       => 'null',
            'from_email' => 'form@example.com',
            'active'     => 'on',
        ])->status());

        $transport = assertNotNull($transports->findByName('форма-транспорт'), 'транспорт не сохранился');
        $id        = (int) $transport['id'];

        assertSame('null', (string) $transport['type']);
        assertSame('form@example.com', (string) $transport['from_email']);
        assertSame('created', (string) assertNotNull(lastAudit('transport'))['action']);

        assertSame(302, uiPost('/ui/transports/save', [
            'id'         => $id,
            'name'       => 'форма-транспорт',
            'type'       => 'null',
            'from_email' => 'other@example.com',
        ])->status());

        assertSame('other@example.com', (string) ((array) $transports->find($id))['from_email']);

        assertSame(302, uiPost('/ui/transports/' . $id . '/delete')->status());
        assertNull($transports->find($id), 'транспорт должен удалиться');
    });
});

test('пароль SMTP из формы ложится в базу зашифрованным', function (): void {
    withConfig(['ui.auth' => true], static function (): void {
        $transports = new TransportRepository();

        assertSame(302, uiPost('/ui/transports/save', [
            'name'       => 'форма-smtp',
            'type'       => 'smtp',
            'host'       => 'smtp.example.com',
            'port'       => 465,
            'username'   => 'user@example.com',
            'password'   => 'пароль-из-формы',
            'encryption' => 'ssl',
            'from_email' => 'smtp@example.com',
        ])->status());

        $id  = (int) ((array) $transports->findByName('форма-smtp'))['id'];
        $raw = (string) ((array) Database::instance()->selectOne(
            'SELECT settings FROM transports WHERE id = :id',
            ['id' => $id]
        ))['settings'];

        assertNotContains('пароль-из-формы', $raw, 'пароль не должен лежать в базе открытым');

        $settings = (array) ((array) $transports->find($id))['settings'];
        assertSame('пароль-из-формы', (string) $settings['password'], 'пароль должен читаться обратно');
        assertSame('smtp.example.com', (string) $settings['host']);

        assertSame(302, uiPost('/ui/transports/' . $id . '/delete')->status());
    });
});

test('шаблон заводится через форму и виден в списке', function (): void {
    withConfig(['ui.auth' => true], static function (): void {
        $templates = new TemplateRepository();

        assertSame(302, uiPost('/ui/templates/save', [
            'name'    => 'форма-шаблон',
            'subject' => 'Тема {{ name }}',
            'text'    => 'Здравствуйте, {{ name }}',
            'html'    => '<p>Здравствуйте, {{ name }}</p>',
        ])->status());

        $template = assertNotNull($templates->findByName('форма-шаблон'), 'шаблон не сохранился');
        assertSame('Тема {{ name }}', (string) $template['subject']);
        assertSame('created', (string) assertNotNull(lastAudit('template'))['action']);

        assertSame(302, uiPost('/ui/templates/' . (int) $template['id'] . '/delete')->status());
        assertNull($templates->find((int) $template['id']), 'шаблон должен удалиться');
    });
});

test('пользователь заводится формой, а пароль хранится хешем', function (): void {
    withConfig(['ui.auth' => true], static function (): void {
        $users = new UserRepository();
        $roles = new RoleRepository();

        assertSame(302, uiPost('/ui/users/save', [
            'login'           => 'forma-user',
            'name'            => 'Из формы',
            'password'        => 'parol123',
            'password_repeat' => 'parol123',
            'role_id'         => (int) ((array) $roles->admin())['id'],
            'active'          => 'on',
        ])->status());

        $user = assertNotNull($users->findByLogin('forma-user'), 'пользователь не сохранился');

        assertNotContains('parol123', (string) $user['password_hash'], 'пароль не должен лежать открытым');
        assertNotNull($users->verify('forma-user', 'parol123'), 'заведённый пользователь должен входить');
        assertSame('created', (string) assertNotNull(lastAudit('user'))['action']);

        $users->delete((int) $user['id'], true);
    });
});

test('короткий пароль формой не проходит', function (): void {
    withConfig(['ui.auth' => true], static function (): void {
        $users = new UserRepository();

        assertSame(302, uiPost('/ui/users/save', [
            'login'           => 'forma-korotkiy',
            'password'        => '123',
            'password_repeat' => '123',
        ])->status());

        assertNull($users->findByLogin('forma-korotkiy'), 'пользователь с коротким паролем не должен появиться');
    });
});

test('пароль, набранный дважды по-разному, не сохраняется', function (): void {
    withConfig(['ui.auth' => true], static function (): void {
        $users = new UserRepository();

        assertSame(302, uiPost('/ui/users/save', [
            'login'           => 'forma-raznye',
            'password'        => 'parol123',
            'password_repeat' => 'parol124',
        ])->status());

        assertNull($users->findByLogin('forma-raznye'), 'при разных паролях пользователь не заводится');
    });
});

test('роль заводится с выбранными правами', function (): void {
    withConfig(['ui.auth' => true], static function (): void {
        $roles = new RoleRepository();

        assertSame(302, uiPost('/ui/roles/save', [
            'name'        => 'форма-роль',
            'permissions' => [Mailer\Domain\Permission::MESSAGES_VIEW, Mailer\Domain\Permission::AUDIT_VIEW],
        ])->status());

        $role = assertNotNull($roles->findByName('форма-роль'), 'роль не сохранилась');

        assertSame(
            [Mailer\Domain\Permission::MESSAGES_VIEW, Mailer\Domain\Permission::AUDIT_VIEW],
            $role['permissions'],
            'права роли записались не те'
        );

        assertSame(302, uiPost('/ui/roles/' . (int) $role['id'] . '/delete')->status());
        assertNull($roles->find((int) $role['id']), 'роль должна удалиться');
    });
});

test('встроенную роль формой не испортить', function (): void {
    withConfig(['ui.auth' => true], static function (): void {
        $roles = new RoleRepository();
        $admin = (array) $roles->admin();

        uiPost('/ui/roles/save', [
            'id'          => (int) $admin['id'],
            'name'        => 'Администратор',
            'permissions' => [Mailer\Domain\Permission::MESSAGES_VIEW],
        ]);

        assertSame(
            Mailer\Domain\Permission::all(),
            ((array) $roles->find((int) $admin['id']))['permissions'],
            'у встроенной роли остаются все права из кода'
        );

        // И удалить её нельзя — иначе панель останется без хозяина
        uiPost('/ui/roles/' . (int) $admin['id'] . '/delete');

        assertNotNull($roles->find((int) $admin['id']), 'встроенную роль удалять нельзя');
    });
});

test('адрес закрывается и открывается через панель', function (): void {
    withConfig(['ui.auth' => true], static function (): void {
        $list = new SuppressionRepository();

        assertSame(302, uiPost('/ui/suppressions', [
            'email'  => 'Panel@Example.com',
            'reason' => 'manual',
            'note'   => 'закрыт из панели',
        ])->status());

        $row = assertNotNull(suppressionByEmail('panel@example.com'), 'адрес не закрылся');
        assertSame('panel@example.com', (string) $row['email'], 'адрес должен храниться в нижнем регистре');
        assertTrue($list->isBlocked('panel@example.com'), 'закрытый адрес должен считаться закрытым');
        assertSame('created', (string) assertNotNull(lastAudit('suppression'))['action']);

        assertSame(302, uiPost('/ui/suppressions/' . (int) $row['id'] . '/delete')->status());
        assertNull(suppressionByEmail('panel@example.com'), 'адрес должен открыться обратно');
        assertFalse($list->isBlocked('panel@example.com'), 'открытый адрес больше не закрыт');
    });
});

test('форма без токена не меняет данные', function (): void {
    withConfig(['ui.auth' => true], static function (): void {
        accessLogin(uiWriteAdmin());

        $before   = Database::instance()->count('projects');
        $response = (new UiKernel())->handle(
            httpRequest('POST', '/ui/projects/save', [], ['name' => 'проект-без-токена'])
        );

        accessLogout();

        assertStatus(403, $response, 'без токена форма не должна проходить');
        assertSame($before, Database::instance()->count('projects'), 'запись всё-таки появилась');
    });
});

test('журнал действий пишется от имени того, кто нажал', function (): void {
    withConfig(['ui.auth' => true], static function (): void {
        uiPost('/ui/suppressions', ['email' => 'audit-check@example.com', 'reason' => 'manual']);

        $audit = assertNotNull(lastAudit('suppression'));

        assertSame(uiWriteAdmin(), (int) $audit['user_id'], 'в журнале должен стоять нажавший');
        assertSame('ui-write', (string) $audit['user_login'], 'логин пишется строкой — пользователя могут удалить');

        $row = (array) suppressionByEmail('audit-check@example.com');
        uiPost('/ui/suppressions/' . (int) $row['id'] . '/delete');
    });
});
