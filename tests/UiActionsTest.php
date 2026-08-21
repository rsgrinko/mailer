<?php

declare(strict_types=1);

/**
 * Действия панели над письмами: отправка из формы «Написать» и массовые кнопки
 * над списком. Массовые действия — единственное место, где панель меняет много
 * записей разом, и до сих пор они не проверялись вовсе.
 *
 * Каждый тест идёт на своей базе: он разгребает очередь целиком по статусу,
 * и на общей базе задел бы письма соседей.
 */

use Mailer\MailService;
use Mailer\Repository\MessageRepository;
use Mailer\Repository\ProjectRepository;
use Mailer\Repository\RoleRepository;
use Mailer\Repository\TemplateRepository;
use Mailer\Repository\TransportRepository;
use Mailer\Repository\UserRepository;
use Mailer\Storage\Database;
use Mailer\Ui\Csrf;
use Mailer\Ui\UiKernel;

/**
 * Обстановка для действий: администратор, транспорт-заглушка, проект и шаблон.
 *
 * @return array<string, mixed>
 */
function actionsFixtures(): array
{
    $users = new UserRepository();

    $admin = $users->create([
        'login'    => 'ui-actions',
        'password' => 'parol123',
        'role_id'  => (int) ((array) (new RoleRepository())->admin())['id'],
    ]);

    $transport = (new TransportRepository())->create([
        'name'       => 'действия-null',
        'type'       => 'null',
        'settings'   => [],
        'from_email' => 'noreply@example.com',
        'is_default' => true,
    ]);

    $template = (new TemplateRepository())->create([
        'name'    => 'действия-шаблон',
        'subject' => 'Здравствуйте, {{ name }}',
        'text'    => 'Ваш код: {{ code }}',
    ]);

    $project = (new ProjectRepository())->create(['name' => 'действия-проект'])['project'];

    return [
        'admin'     => (int) $admin['id'],
        'transport' => (int) $transport,
        'template'  => (int) $template,
        'project'   => (int) $project['id'],
    ];
}

/**
 * POST в панель от администратора этой базы.
 *
 * @param array<string, mixed> $body
 */
function actionsPost(int $userId, string $path, array $body = []): Mailer\Http\Response
{
    accessLogin($userId);

    $response = (new UiKernel())->handle(
        httpRequest('POST', $path, ['x-csrf-token' => Csrf::token()], $body)
    );

    accessLogout();

    return $response;
}

/**
 * Кладёт в очередь письмо и возвращает его id.
 */
function actionsMessage(string $subject, string $status = ''): int
{
    $id = (int) (new MailService())->accept([
        'to'        => 'user@example.com',
        'subject'   => $subject,
        'text'      => 'текст',
        'transport' => 'действия-null',
    ])['id'];

    if ($status !== '') {
        (new MessageRepository())->update($id, ['status' => $status]);
    }

    return $id;
}

test('письмо из формы «Написать» уходит в очередь', function (): void {
    withOwnDatabase(static function (): void {
        $ids = actionsFixtures();

        withConfig(['ui.auth' => true], static function () use ($ids): void {
            $response = actionsPost($ids['admin'], '/ui/compose', [
                'to'           => 'komu@example.com',
                'subject'      => 'Письмо из панели',
                'text'         => 'Текст письма',
                'transport_id' => $ids['transport'],
                'project_id'   => $ids['project'],
            ]);

            assertStatus(302, $response, 'после отправки должен быть переход');

            $page = (new MessageRepository())->paginate(['search' => 'komu@example.com'], 1, 5);

            assertSame(1, $page['total'], 'письмо должно попасть в очередь');

            $message = $page['items'][0];

            assertSame('Письмо из панели', (string) $message['subject']);
            assertSame('ui', (string) $message['source'], 'источник — панель');
            assertSame($ids['project'], (int) $message['project_id'], 'проект из формы');

            // Владельца в списке нет — смотрим карточку письма целиком
            $full = (array) (new MessageRepository())->find((int) $message['id']);

            assertSame($ids['admin'], (int) $full['owner_id'], 'владелец — тот, кто нажал');
        });
    });
});

test('письмо из панели можно отправить сразу', function (): void {
    withOwnDatabase(static function (): void {
        $ids = actionsFixtures();

        withConfig(['ui.auth' => true], static function () use ($ids): void {
            actionsPost($ids['admin'], '/ui/compose', [
                'to'           => 'srazu@example.com',
                'subject'      => 'Синхронное письмо',
                'text'         => 'Текст',
                'transport_id' => $ids['transport'],
                'sync'         => 'on',
            ]);

            $page = (new MessageRepository())->paginate(['search' => 'srazu@example.com'], 1, 5);

            assertSame('sent', (string) $page['items'][0]['status'], 'с галкой «отправить сразу» письмо уходит');
        });
    });
});

test('письмо по шаблону подставляет данные', function (): void {
    withOwnDatabase(static function (): void {
        $ids = actionsFixtures();

        withConfig(['ui.auth' => true], static function () use ($ids): void {
            actionsPost($ids['admin'], '/ui/compose', [
                'to'            => 'shablon@example.com',
                'template'      => 'действия-шаблон',
                'template_data' => '{"name":"Иван","code":"1234"}',
                'transport_id'  => $ids['transport'],
            ]);

            $page = (new MessageRepository())->paginate(['search' => 'shablon@example.com'], 1, 5);

            assertSame(1, $page['total'], 'письмо по шаблону должно появиться');
            assertSame('Здравствуйте, Иван', (string) $page['items'][0]['subject'], 'тема из шаблона с подстановкой');
        });
    });
});

test('кривой JSON данных шаблона не роняет форму', function (): void {
    withOwnDatabase(static function (): void {
        $ids = actionsFixtures();

        withConfig(['ui.auth' => true], static function () use ($ids): void {
            $before = Database::instance()->count('messages');

            $response = actionsPost($ids['admin'], '/ui/compose', [
                'to'            => 'krivoy@example.com',
                'subject'       => 'С кривым JSON',
                'text'          => 'текст',
                'template_data' => '{это не json}',
                'transport_id'  => $ids['transport'],
            ]);

            assertStatus(302, $response, 'форма возвращает на страницу с сообщением');
            assertSame($before, Database::instance()->count('messages'), 'письмо появиться не должно');
        });
    });
});

test('письмо без получателя из панели не принимается', function (): void {
    withOwnDatabase(static function (): void {
        $ids = actionsFixtures();

        withConfig(['ui.auth' => true], static function () use ($ids): void {
            $before = Database::instance()->count('messages');

            actionsPost($ids['admin'], '/ui/compose', [
                'subject'      => 'Без адресата',
                'text'         => 'текст',
                'transport_id' => $ids['transport'],
            ]);

            assertSame($before, Database::instance()->count('messages'), 'письмо без получателя не сохраняется');
        });
    });
});

test('массовый повтор возвращает в очередь все неудачные письма', function (): void {
    withOwnDatabase(static function (): void {
        $ids      = actionsFixtures();
        $messages = new MessageRepository();

        $failed = [
            actionsMessage('Неудача 1', MessageRepository::FAILED),
            actionsMessage('Неудача 2', MessageRepository::FAILED),
        ];

        $queued = actionsMessage('Просто в очереди');

        withConfig(['ui.auth' => true], static function () use ($ids, $messages, $failed, $queued): void {
            $response = actionsPost($ids['admin'], '/ui/messages/bulk', [
                'action' => 'retry',
                'status' => MessageRepository::FAILED,
            ]);

            assertStatus(302, $response);

            foreach ($failed as $id) {
                assertSame('queued', (string) ((array) $messages->find($id))['status'], 'неудачное должно вернуться');
            }

            assertSame(
                'queued',
                (string) ((array) $messages->find($queued))['status'],
                'письмо другого статуса трогать не должны'
            );

            $audit = (array) Database::instance()->selectOne(
                'SELECT summary FROM audit_log ORDER BY id DESC LIMIT 1'
            );

            assertContains('массовое действие', (string) $audit['summary'], 'действие должно попасть в журнал');
            assertContains('писем: 2', (string) $audit['summary'], 'и число обработанных писем');
        });
    });
});

test('массовая отмена убирает письма из очереди', function (): void {
    withOwnDatabase(static function (): void {
        $ids      = actionsFixtures();
        $messages = new MessageRepository();

        $queued = [actionsMessage('Отменить 1'), actionsMessage('Отменить 2')];
        $sent   = actionsMessage('Уже ушло', MessageRepository::SENT);

        withConfig(['ui.auth' => true], static function () use ($ids, $messages, $queued, $sent): void {
            actionsPost($ids['admin'], '/ui/messages/bulk', [
                'action' => 'cancel',
                'status' => MessageRepository::QUEUED,
            ]);

            foreach ($queued as $id) {
                assertSame('canceled', (string) ((array) $messages->find($id))['status'], 'письмо должно отмениться');
            }

            assertSame('sent', (string) ((array) $messages->find($sent))['status'], 'отправленное не трогаем');
        });
    });
});

test('массовое удаление уносит письма вместе с событиями', function (): void {
    withOwnDatabase(static function (Database $db): void {
        $ids      = actionsFixtures();
        $messages = new MessageRepository();

        $doomed = [
            actionsMessage('Удалить 1', MessageRepository::CANCELED),
            actionsMessage('Удалить 2', MessageRepository::CANCELED),
        ];

        $keep = actionsMessage('Остаться в очереди');

        withConfig(['ui.auth' => true], static function () use ($ids, $messages, $doomed, $keep, $db): void {
            actionsPost($ids['admin'], '/ui/messages/bulk', [
                'action' => 'delete',
                'status' => MessageRepository::CANCELED,
            ]);

            foreach ($doomed as $id) {
                assertNull($messages->find($id), 'письмо должно удалиться');
                assertSame(
                    0,
                    (int) ((array) $db->selectOne(
                        'SELECT COUNT(*) AS c FROM message_events WHERE message_id = :id',
                        ['id' => $id]
                    ))['c'],
                    'события удалённого письма не должны оставаться'
                );
            }

            assertNotNull($messages->find($keep), 'письмо другого статуса остаётся');
        });
    });
});

test('с выключенными действиями массовые кнопки ничего не делают', function (): void {
    withOwnDatabase(static function (): void {
        $ids      = actionsFixtures();
        $messages = new MessageRepository();

        $failed = actionsMessage('Не должно вернуться', MessageRepository::FAILED);

        withConfig(['ui.auth' => true, 'ui.allow_actions' => false], static function () use ($ids, $messages, $failed): void {
            $response = actionsPost($ids['admin'], '/ui/messages/bulk', [
                'action' => 'retry',
                'status' => MessageRepository::FAILED,
            ]);

            assertStatus(302, $response);
            assertSame(
                'failed',
                (string) ((array) $messages->find($failed))['status'],
                'с UI_ALLOW_ACTIONS=false запрос должен отклоняться'
            );
        });
    });
});

test('неизвестное массовое действие ничего не меняет', function (): void {
    withOwnDatabase(static function (): void {
        $ids      = actionsFixtures();
        $messages = new MessageRepository();

        $failed = actionsMessage('Целое письмо', MessageRepository::FAILED);

        withConfig(['ui.auth' => true], static function () use ($ids, $messages, $failed): void {
            actionsPost($ids['admin'], '/ui/messages/bulk', [
                'action' => 'сделать-хорошо',
                'status' => MessageRepository::FAILED,
            ]);

            assertSame('failed', (string) ((array) $messages->find($failed))['status'], 'статус меняться не должен');
        });
    });
});

test('кнопки над одним письмом: повтор, отмена, отправка, удаление', function (): void {
    withOwnDatabase(static function (): void {
        $ids      = actionsFixtures();
        $messages = new MessageRepository();

        withConfig(['ui.auth' => true], static function () use ($ids, $messages): void {
            // Повтор возвращает неудачное письмо в очередь
            $failed = actionsMessage('Кнопка повтора', MessageRepository::FAILED);

            assertStatus(302, actionsPost($ids['admin'], '/ui/messages/' . $failed . '/retry'));
            assertSame('queued', (string) ((array) $messages->find($failed))['status']);

            // Отмена убирает письмо из очереди
            assertStatus(302, actionsPost($ids['admin'], '/ui/messages/' . $failed . '/cancel'));
            assertSame('canceled', (string) ((array) $messages->find($failed))['status']);

            // «Отправить сейчас» отправляет письмо из очереди
            $queued = actionsMessage('Кнопка отправки');

            assertStatus(302, actionsPost($ids['admin'], '/ui/messages/' . $queued . '/send'));
            assertSame('sent', (string) ((array) $messages->find($queued))['status']);

            // Отправленное повторить нельзя — статус не меняется
            assertStatus(302, actionsPost($ids['admin'], '/ui/messages/' . $queued . '/retry'));
            assertSame('sent', (string) ((array) $messages->find($queued))['status'], 'дубль отправлять нельзя');

            // Удаление уносит письмо и уводит в список
            $response = actionsPost($ids['admin'], '/ui/messages/' . $queued . '/delete');

            assertStatus(302, $response);
            assertSame('/ui/messages', $response->headers()['Location'] ?? '', 'после удаления возвращаемся в список');
            assertNull($messages->find($queued), 'письмо должно удалиться');
        });
    });
});

test('неизвестное действие над письмом ничего не ломает', function (): void {
    withOwnDatabase(static function (): void {
        $ids = actionsFixtures();
        $id  = actionsMessage('Целое письмо');

        withConfig(['ui.auth' => true], static function () use ($ids, $id): void {
            $response = actionsPost($ids['admin'], '/ui/messages/' . $id . '/полетели');

            assertStatus(302, $response);
            assertSame(
                'queued',
                (string) ((array) (new MessageRepository())->find($id))['status'],
                'статус меняться не должен'
            );
        });
    });
});

test('обслуживание сервиса из панели: зависшие, счётчики, разовый проход', function (): void {
    withOwnDatabase(static function (Database $db): void {
        $ids      = actionsFixtures();
        $messages = new MessageRepository();

        withConfig(['ui.auth' => true], static function () use ($ids, $messages, $db): void {
            // Зависшее письмо — то, что взял и не довёл упавший воркер
            $stuck = actionsMessage('Зависшее письмо');

            $db->update(
                'messages',
                [
                    'status'    => MessageRepository::SENDING,
                    'locked_at' => date('Y-m-d H:i:s', time() - 7200),
                    'locked_by' => 'умерший-воркер',
                ],
                ['id' => $stuck]
            );

            assertStatus(302, actionsPost($ids['admin'], '/ui/system/requeue'));
            assertSame('queued', (string) ((array) $messages->find($stuck))['status'], 'зависшее должно вернуться');

            // Разовый проход воркера разгребает очередь
            assertStatus(302, actionsPost($ids['admin'], '/ui/system/worker-once'));
            assertSame('sent', (string) ((array) $messages->find($stuck))['status'], 'после прохода письмо уходит');

            // Счётчики лимитов чистятся и сбрасываются
            assertStatus(302, actionsPost($ids['admin'], '/ui/system/cleanup-counters'));
            assertStatus(302, actionsPost($ids['admin'], '/ui/system/reset-counters'));
            assertSame(0, $db->count('counters'), 'после сброса счётчиков не остаётся');

            // Просьба о перезапуске воркера ложится в settings
            assertStatus(302, actionsPost($ids['admin'], '/ui/system/restart-worker'));
            assertNotNull(
                (new Mailer\Repository\SettingRepository($db))->get(Mailer\Queue\Worker::RESTART_KEY),
                'просьба о перезапуске должна сохраниться'
            );

            // Все действия попали в журнал
            $summaries = array_column(
                $db->select("SELECT summary FROM audit_log WHERE entity = 'system'"),
                'summary'
            );

            assertTrue(count($summaries) >= 5, 'каждое действие обслуживания пишется в журнал');
        });
    });
});

test('чистка старых писем удаляет только подходящие под условие', function (): void {
    withOwnDatabase(static function (Database $db): void {
        $ids      = actionsFixtures();
        $messages = new MessageRepository();

        withConfig(['ui.auth' => true], static function () use ($ids, $messages, $db): void {
            $old   = actionsMessage('Старое отправленное', MessageRepository::SENT);
            $fresh = actionsMessage('Свежее отправленное', MessageRepository::SENT);
            $other = actionsMessage('Старое неудачное', MessageRepository::FAILED);

            // Возраст письма для чистки считается по updated_at: важно, когда с ним
            // в последний раз что-то происходило, а не когда его приняли
            foreach ([$old, $other] as $id) {
                $db->update('messages', [
                    'created_at' => date('Y-m-d H:i:s', strtotime('-100 days')),
                    'updated_at' => date('Y-m-d H:i:s', strtotime('-100 days')),
                ], ['id' => $id]);
            }

            assertStatus(302, actionsPost($ids['admin'], '/ui/system/purge', [
                'status' => MessageRepository::SENT,
                'days'   => 30,
            ]));

            assertNull($messages->find($old), 'старое письмо нужного статуса должно удалиться');
            assertNotNull($messages->find($fresh), 'свежее остаётся');
            assertNotNull($messages->find($other), 'письмо другого статуса остаётся');
        });
    });
});

test('неизвестное действие обслуживания ничего не делает', function (): void {
    withOwnDatabase(static function (Database $db): void {
        $ids = actionsFixtures();
        $id  = actionsMessage('Письмо на месте');

        withConfig(['ui.auth' => true], static function () use ($ids, $id, $db): void {
            assertStatus(302, actionsPost($ids['admin'], '/ui/system/сделать-хорошо'));

            assertNotNull((new MessageRepository())->find($id), 'письмо должно остаться');
        });
    });
});
