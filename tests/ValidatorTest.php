<?php

declare(strict_types=1);

/**
 * Проверка входных данных. Раньше валидатор проверялся только «через API»,
 * поэтому его собственные правила — длина адреса, домен без точки, накопление
 * всех ошибок разом — оставались на честном слове.
 */

use Mailer\Support\ValidationException;
use Mailer\Support\Validator;

test('адрес принимается только с доменом и точкой', function (): void {
    assertTrue(Validator::isEmail('user@example.com'));
    assertTrue(Validator::isEmail('user.name+tag@sub.example.co.uk'));
    assertTrue(Validator::isEmail('  user@example.com  '), 'лишние пробелы по краям не мешают');

    assertFalse(Validator::isEmail(''), 'пустая строка адресом не является');
    assertFalse(Validator::isEmail('user'), 'без домена адреса нет');
    assertFalse(Validator::isEmail('user@localhost'), 'домен без точки не принимаем: письмо всё равно не уйдёт');
    assertFalse(Validator::isEmail('user@@example.com'));
    assertFalse(Validator::isEmail('без адреса'));
});

test('слишком длинный адрес не проходит', function (): void {
    $long = str_repeat('a', 250) . '@example.com';

    assertTrue(strlen($long) > 254, 'для проверки нужен адрес длиннее предела');
    assertFalse(Validator::isEmail($long), 'адрес длиннее 254 символов принимать нельзя');

    assertTrue(Validator::isEmail(str_repeat('a', 60) . '@example.com'), 'длинный, но допустимый адрес проходит');
});

test('валидатор копит все ошибки разом', function (): void {
    $validator = new Validator();

    assertFalse($validator->fails(), 'пустой валидатор ошибок не знает');

    $validator->check(Validator::isEmail('не адрес'), 'Неверный адрес получателя');
    $validator->check(false, 'Пустая тема');
    $validator->check(true, 'Эта проверка прошла');
    $validator->add('И ещё одна беда');

    assertTrue($validator->fails());
    assertSame(
        ['Неверный адрес получателя', 'Пустая тема', 'И ещё одна беда'],
        $validator->errors(),
        'в списке должны быть только настоящие ошибки и в порядке проверок'
    );
});

test('исключение уносит с собой все ошибки', function (): void {
    $validator = new Validator();
    $validator->add('Первая');
    $validator->add('Вторая');

    $error = assertThrows(static fn () => $validator->throwIfFails());

    assertTrue($error instanceof ValidationException);
    assertSame(['Первая', 'Вторая'], $error->errors(), 'клиент должен увидеть сразу все проблемы');
});

test('без ошибок исключения нет', function (): void {
    $validator = new Validator();
    $validator->check(true, 'всё хорошо');

    $validator->throwIfFails();

    assertSame([], $validator->errors());
});
