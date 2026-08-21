<?php

declare(strict_types=1);

/**
 * Объекты предметной области: разбор строки базы.
 */

use Mailer\Domain\Project;
use Mailer\Domain\TransportProfile;

test('проект собирается из строки базы', function (): void {
    $project = Project::fromRow([
        'id'                 => '7',
        'name'               => 'shop',
        'active'             => '1',
        'rate_limit_hour'    => '100',
        'rate_limit_day'     => '0',
        'transport_id'       => null,
        'default_from_email' => ' shop@example.com ',
        'default_from_name'  => '',
    ]);

    assertSame(7, $project->id);
    assertSame('shop', $project->name);
    assertTrue($project->active);
    assertSame(100, $project->rateLimitHour);
    assertSame(0, $project->rateLimitDay);
    assertSame(null, $project->transportId);
    assertSame('shop@example.com', $project->defaultFromEmail, 'пробелы по краям убираются');
    assertSame(null, $project->defaultFromName, 'пустая строка — это отсутствие значения');
});

test('отключённый проект виден по флагу', function (): void {
    assertFalse(Project::fromRow(['id' => 1, 'name' => 'x', 'active' => '0'])->active);
});

test('профиль транспорта собирается из строки базы', function (): void {
    $transport = TransportProfile::fromRow([
        'id'           => '3',
        'name'         => 'yandex',
        'type'         => 'smtp',
        'active'       => 1,
        'is_default'   => 1,
        'daily_limit'  => '500',
        'from_email'   => 'noreply@example.com',
    ]);

    assertSame(3, $transport->id);
    assertSame('yandex', $transport->name);
    assertSame('smtp', $transport->type);
    assertTrue($transport->active);
    assertTrue($transport->isDefault);
    assertSame(500, $transport->dailyLimit);
    assertSame('noreply@example.com', $transport->fromEmail);
    assertSame(null, $transport->fromName);
});

test('пустая строка базы не роняет разбор', function (): void {
    $project = Project::fromRow([]);

    assertSame(0, $project->id);
    assertSame('', $project->name);
    assertTrue($project->active, 'по умолчанию проект считается активным');
});
