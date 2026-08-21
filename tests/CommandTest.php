<?php

declare(strict_types=1);

/**
 * Консольные команды в работе. Раньше проверялся только реестр: имена, описания
 * и справка, — а сам run() не выполнялся ни у одной. Между тем именно консолью
 * правят прод: заводят ключи, пользователей, транспорты, разбирают очередь.
 *
 * Каждая команда идёт на своей чистой базе: они меняют данные, а иногда и всю
 * таблицу разом.
 */

use Mailer\Console\Application;
use Mailer\Console\Command;
use Mailer\MailService;
use Mailer\Repository\MessageRepository;
use Mailer\Repository\ProjectRepository;
use Mailer\Repository\SettingRepository;
use Mailer\Repository\TransportRepository;
use Mailer\Repository\UserRepository;
use Mailer\Storage\Database;

/**
 * Выполняет команду и возвращает её вывод вместе с кодом выхода.
 *
 * @param  array<int, string>    $args
 * @param  array<string, string> $options
 * @return array{code: int, output: string}
 */
function runCommand(string $name, array $args = [], array $options = []): array
{
    $command = null;

    foreach (Application::commands() as $candidate) {
        if ($candidate->name() === $name) {
            $command = $candidate;
        }
    }

    if (!$command instanceof Command) {
        throw new RuntimeException('Нет такой команды: ' . $name);
    }

    $lines = [];

    $output = static function (string $line) use (&$lines): void {
        $lines[] = $line;
    };

    try {
        $code = $command->withInput($args, $options, $output)->run();
    } catch (Throwable $e) {
        // Application ловит исключения команды и печатает их — повторяем это здесь
        $lines[] = "ОШИБКА: " . $e->getMessage();
        $code    = 1;
    }

    return ['code' => $code, 'output' => implode(PHP_EOL, $lines)];
}

/**
 * Транспорт-заглушка в текущей базе.
 */
function commandTransport(string $name = 'консоль-null'): array
{
    $transports = new TransportRepository();

    if ($transports->findByName($name) === null) {
        $transports->create([
            'name'       => $name,
            'type'       => 'null',
            'settings'   => [],
            'from_email' => 'noreply@example.com',
            'is_default' => true,
        ]);
    }

    return (array) $transports->findByName($name);
}

test('key:create заводит проект и печатает ключ один раз', function (): void {
    withOwnDatabase(static function (): void {
        $result = runCommand('key:create', ['консольный-проект']);

        assertSame(0, $result['code'], 'команда должна отработать: ' . $result['output']);

        $project = assertNotNull(
            (new ProjectRepository())->findByName('консольный-проект'),
            'проект не завёлся'
        );

        // Ключ печатается целиком — второй раз его взять негде
        assertMatches('/mlr_[a-zA-Z0-9]+_[a-zA-Z0-9]+/', $result['output'], 'в выводе должен быть ключ');

        // В базе только хеш и префикс
        $row = (array) Database::instance()->selectOne(
            'SELECT api_key_hash, api_key_prefix FROM projects WHERE id = :id',
            ['id' => (int) $project['id']]
        );

        assertTrue((string) $row['api_key_hash'] !== '', 'хеш ключа должен сохраниться');
        assertNotContains((string) $row['api_key_hash'], $result['output'], 'хеш печатать незачем');

        // Без имени команда объясняет, чего от неё хотят, и возвращает 1
        $empty = runCommand('key:create');
        assertSame(1, $empty['code']);
        assertContains('Укажите имя проекта', $empty['output']);
    });
});

test('key:list показывает заведённые проекты, key:revoke отзывает ключ', function (): void {
    withOwnDatabase(static function (): void {
        runCommand('key:create', ['для-отзыва']);

        $list = runCommand('key:list');

        assertSame(0, $list['code']);
        assertContains('для-отзыва', $list['output'], 'проект должен быть в списке');

        $project = (array) (new ProjectRepository())->findByName('для-отзыва');
        $revoke  = runCommand('key:revoke', ['для-отзыва']);

        assertSame(0, $revoke['code'], $revoke['output']);
        assertSame(
            0,
            (int) ((array) (new ProjectRepository())->find((int) $project['id']))['active'],
            'после отзыва проект должен быть выключен'
        );
    });
});

test('user:create заводит пользователя и печатает пароль, если его не задали', function (): void {
    withOwnDatabase(static function (): void {
        $result = runCommand('user:create', ['konsolnyy'], ['name' => 'Из консоли']);

        assertSame(0, $result['code'], $result['output']);

        $user = assertNotNull((new UserRepository())->findByLogin('konsolnyy'), 'пользователь не завёлся');

        assertSame('Из консоли', (string) $user['name']);
        assertContains('Пароль', $result['output'], 'сгенерированный пароль надо показать');

        // Свой пароль тоже принимается и работает
        runCommand('user:create', ['svoy-parol'], ['password' => 'parol123']);

        assertNotNull(
            (new UserRepository())->verify('svoy-parol', 'parol123'),
            'заведённый с паролем пользователь должен входить'
        );

        // Повтор логина — понятная ошибка, а не исключение наружу
        $again = runCommand('user:create', ['konsolnyy'], ['password' => 'parol123']);
        assertSame(1, $again['code']);
        assertContains('уже', $again['output']);
    });
});

test('user:password меняет пароль, user:delete удаляет пользователя', function (): void {
    withOwnDatabase(static function (): void {
        $users = new UserRepository();

        runCommand('user:create', ['ostayus'], ['password' => 'parol123']);
        runCommand('user:create', ['menyayu'], ['password' => 'parol123']);

        $result = runCommand('user:password', ['menyayu'], ['password' => 'novyy123']);

        assertSame(0, $result['code'], $result['output']);
        assertNull($users->verify('menyayu', 'parol123'), 'старый пароль работать не должен');
        assertNotNull($users->verify('menyayu', 'novyy123'), 'новый пароль должен подходить');

        $user   = (array) $users->findByLogin('menyayu');
        $delete = runCommand('user:delete', ['menyayu']);

        assertSame(0, $delete['code'], $delete['output']);
        assertNull($users->find((int) $user['id']), 'пользователь должен удалиться');

        // Последнего активного команда удалить не даёт
        $last = runCommand('user:delete', ['ostayus']);
        assertSame(1, $last['code'], 'последнего пользователя удалять нельзя');
        assertNotNull($users->findByLogin('ostayus'), 'и он должен остаться в базе');
    });
});

test('user:list печатает пользователей с ролями', function (): void {
    withOwnDatabase(static function (): void {
        runCommand('user:create', ['spisok'], ['password' => 'parol123', 'role' => 'Администратор']);

        $result = runCommand('user:list');

        assertSame(0, $result['code']);
        assertContains('spisok', $result['output']);
        assertContains('Администратор', $result['output'], 'роль должна быть видна в списке');
    });
});

test('transport:add заводит транспорт, transport:default делает его основным', function (): void {
    withOwnDatabase(static function (): void {
        $transports = new TransportRepository();

        $result = runCommand('transport:add', ['консоль-smtp'], [
            'type'       => 'smtp',
            'host'       => 'smtp.example.com',
            'port'       => '465',
            'encryption' => 'ssl',
            'user'       => 'user@example.com',
            'password'   => 'секрет-из-консоли',
            'from'       => 'from@example.com',
        ]);

        assertSame(0, $result['code'], $result['output']);

        $transport = assertNotNull($transports->findByName('консоль-smtp'), 'транспорт не завёлся');

        assertSame('smtp', (string) $transport['type']);
        assertSame('smtp.example.com', (string) ((array) $transport['settings'])['host']);

        // Пароль в базе лежит зашифрованным, если ключ есть, и в выводе его нет
        assertNotContains('секрет-из-консоли', $result['output'], 'пароль печатать нельзя');

        commandTransport();
        $default = runCommand('transport:default', ['консоль-smtp']);

        assertSame(0, $default['code'], $default['output']);
        assertSame(
            1,
            (int) ((array) $transports->find((int) $transport['id']))['is_default'],
            'транспорт должен стать основным'
        );

        $list = runCommand('transport:list');
        assertContains('консоль-smtp', $list['output']);
    });
});

test('suppress:add закрывает адрес, suppress:remove открывает', function (): void {
    withOwnDatabase(static function (): void {
        $list = new Mailer\Repository\SuppressionRepository();

        $added = runCommand('suppress:add', ['Konsol@Example.com'], ['reason' => 'complaint', 'note' => 'жалоба']);

        assertSame(0, $added['code'], $added['output']);
        assertTrue($list->isBlocked('konsol@example.com'), 'адрес должен закрыться');

        $shown = runCommand('suppress:list', [], ['search' => 'konsol']);
        assertContains('konsol@example.com', $shown['output']);

        $removed = runCommand('suppress:remove', ['konsol@example.com']);

        assertSame(0, $removed['code'], $removed['output']);
        assertFalse($list->isBlocked('konsol@example.com'), 'адрес должен открыться');

        // Кривой адрес не принимается
        assertSame(1, runCommand('suppress:add', ['не адрес'])['code']);
    });
});

test('queue:status считает письма по статусам', function (): void {
    withOwnDatabase(static function (): void {
        commandTransport();

        (new MailService())->accept([
            'to'        => 'queue@example.com',
            'subject'   => 'Письмо для очереди',
            'text'      => 'текст',
            'transport' => 'консоль-null',
        ]);

        $result = runCommand('queue:status');

        assertSame(0, $result['code']);
        assertContains('В очереди готовы:', $result['output'], 'в сводке должны быть статусы');
        assertMatches('/В очереди готовы:\s+1/u', $result['output'], 'письмо должно попасть в счётчик');
    });
});

test('queue:retry возвращает неудачное письмо в очередь', function (): void {
    withOwnDatabase(static function (): void {
        commandTransport();

        $accepted = (new MailService())->accept([
            'to'        => 'retry@example.com',
            'subject'   => 'Неудачное письмо',
            'text'      => 'текст',
            'transport' => 'консоль-null',
        ]);

        $messages = new MessageRepository();
        $id       = (int) $accepted['id'];

        $messages->update($id, ['status' => MessageRepository::FAILED, 'last_error' => 'сервер отказал']);

        $result = runCommand('queue:retry', [(string) $id]);

        assertSame(0, $result['code'], $result['output']);
        assertSame('queued', (string) ((array) $messages->find($id))['status'], 'письмо должно вернуться в очередь');

        // --failed забирает все неудачные разом
        $second = (int) (new MailService())->accept([
            'to'        => 'retry2@example.com',
            'subject'   => 'И это тоже',
            'text'      => 'текст',
            'transport' => 'консоль-null',
        ])['id'];

        $messages->update($second, ['status' => MessageRepository::FAILED]);

        assertSame(0, runCommand('queue:retry', [], ['failed' => '1'])['code']);
        assertSame('queued', (string) ((array) $messages->find($second))['status']);
    });
});

test('queue:cancel отменяет письмо из очереди', function (): void {
    withOwnDatabase(static function (): void {
        commandTransport();

        $id = (int) (new MailService())->accept([
            'to'        => 'cancel@example.com',
            'subject'   => 'Отменить',
            'text'      => 'текст',
            'transport' => 'консоль-null',
        ])['id'];

        $result = runCommand('queue:cancel', [(string) $id]);

        assertSame(0, $result['code'], $result['output']);
        assertSame('canceled', (string) ((array) (new MessageRepository())->find($id))['status']);
    });
});

test('worker:restart просит воркер перезапуститься', function (): void {
    withOwnDatabase(static function (Database $db): void {
        $settings = new SettingRepository($db);

        // Пока воркер ни разу не запускался, перезапускать нечего — и команда об этом говорит
        $never = runCommand('worker:restart');

        assertSame(1, $never['code']);
        assertContains('ни разу не запускался', $never['output']);

        // Отметка «жив» — так выглядит база при работающем воркере
        $settings->set(Mailer\Queue\Worker::HEARTBEAT_KEY, (string) json_encode(['worker' => 'test:1', 'pid' => 1]));

        $result = runCommand('worker:restart');

        assertSame(0, $result['code'], $result['output']);

        $asked = $settings->get(Mailer\Queue\Worker::RESTART_KEY);

        assertNotNull($asked, 'просьба должна лечь в settings');
        assertTrue((int) $asked > time() - 60, 'и быть свежей');
    });
});

test('role:list показывает роли и их права', function (): void {
    withOwnDatabase(static function (): void {
        $result = runCommand('role:list');

        assertSame(0, $result['code']);
        assertContains('Администратор', $result['output']);

        // С именем роли печатается её список прав
        $one = runCommand('role:list', ['Администратор']);

        assertSame(0, $one['code']);
        assertContains(Mailer\Domain\Permission::DATA_ALL, $one['output'], 'у администратора должно быть право на чужие данные');
    });
});

test('route:list печатает карту адресов', function (): void {
    $result = runCommand('route:list');

    assertSame(0, $result['code']);
    assertContains('/api/v1/messages', $result['output']);
    assertContains('/ui/messages', $result['output']);

    // С фильтром — только подходящие строки
    $filtered = runCommand('route:list', ['suppressions']);

    assertContains('suppressions', $filtered['output']);
    assertNotContains('/ui/templates', $filtered['output'], 'лишние адреса фильтр показывать не должен');
});

test('status показывает состояние сервиса и не падает без воркера', function (): void {
    withOwnDatabase(static function (): void {
        $result = runCommand('status');

        assertSame(0, $result['code'], $result['output']);
        assertContains('База', $result['output']);
    });
});
