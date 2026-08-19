<?php

declare(strict_types=1);

/**
 * Пользователи панели: пароли, вход, ограничения на удаление.
 */

use Mailer\Repository\UserRepository;
use Mailer\Security\Password;

test('пароль короче шести символов не принимается', function (): void {
    assertSame('Пароль должен быть не короче 6 символов', Password::check('12345'));
    assertSame(null, Password::check('123456'));

    assertThrows(static fn () => Password::hash('12345'), 'короткий пароль должен ломать хеширование');
});

test('хеш пароля проверяется и не хранит сам пароль', function (): void {
    $hash = Password::hash('секрет123');

    assertTrue($hash !== 'секрет123');
    assertTrue(Password::verify('секрет123', $hash));
    assertFalse(Password::verify('секрет124', $hash));
});

test('пользователь заводится и входит по логину и паролю', function (): void {
    $users = new UserRepository();
    $users->create(['login' => 'Ivan', 'password' => 'parol123', 'name' => 'Иван']);

    // Логин не зависит от регистра
    assertTrue($users->findByLogin('ivan') !== null);
    assertTrue($users->verify('IVAN', 'parol123') !== null);
    assertSame(null, $users->verify('ivan', 'другое'));
    assertSame(null, $users->verify('net-takogo', 'parol123'));
});

test('отключённый пользователь войти не может', function (): void {
    $users = new UserRepository();
    $users->create(['login' => 'petr', 'password' => 'parol123']);
    $petr = (array) $users->findByLogin('petr');

    $users->update((int) $petr['id'], ['active' => false]);

    assertSame(null, $users->verify('petr', 'parol123'));
});

test('логин проверяется на допустимые символы и повторы', function (): void {
    $users = new UserRepository();
    $users->create(['login' => 'dup', 'password' => 'parol123']);

    foreach (['dup', '', 'иван', 'ivan ivan'] as $login) {
        assertThrows(
            static fn () => $users->create(['login' => $login, 'password' => 'parol123']),
            'логин «' . $login . '» не должен приниматься'
        );
    }
});

test('последнего активного пользователя не отключить и не удалить', function (): void {
    $users = new UserRepository();

    // Оставляем в базе ровно одного — остальных сносим принудительно
    foreach ($users->all() as $user) {
        $users->delete((int) $user['id'], true);
    }

    $users->create(['login' => 'single', 'password' => 'parol123']);
    $single = (array) $users->findByLogin('single');

    assertThrows(static fn () => $users->update((int) $single['id'], ['active' => false]));
    assertThrows(static fn () => $users->delete((int) $single['id']));

    assertSame(1, $users->countActive());
});
