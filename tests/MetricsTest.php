<?php

declare(strict_types=1);

/**
 * Метрики для Prometheus: формат ответа и защита токеном.
 */

use Mailer\Http\ApiKernel;
use Mailer\Support\Config;

test('метрики отдаются в формате Prometheus', function (): void {
    Config::set('metrics.enabled', true);
    Config::set('metrics.token', '');

    $response = (new ApiKernel())->handle(httpRequest('GET', '/metrics'));
    $body     = $response->body();

    assertSame(200, $response->status());
    assertContains('text/plain', $response->headers()['Content-Type'] ?? '');

    assertContains("# TYPE mailer_up gauge\nmailer_up 1", $body);
    assertContains('mailer_messages{status="queued"}', $body);
    assertContains('mailer_queue_ready ', $body);
    assertContains('mailer_worker_up ', $body);
    assertContains('mailer_projects{state="active"}', $body);
    assertContains('mailer_suppressions{reason="bounce"}', $body);

    // Второй адрес — тот же ответ: мониторингу удобнее /metrics, а API-клиентам /api/v1
    assertSame(200, (new ApiKernel())->handle(httpRequest('GET', '/api/v1/metrics'))->status());
});

test('метрики закрываются токеном', function (): void {
    Config::set('metrics.enabled', true);
    Config::set('metrics.token', 'секретный-токен');

    $kernel = new ApiKernel();

    assertSame(401, $kernel->handle(httpRequest('GET', '/metrics'))->status());
    assertSame(401, $kernel->handle(httpRequest('GET', '/metrics', ['authorization' => 'Bearer чужой']))->status());

    assertSame(200, $kernel->handle(httpRequest('GET', '/metrics', ['authorization' => 'Bearer секретный-токен']))->status());
    assertSame(200, $kernel->handle(httpRequest('GET', '/metrics', [], [], ['token' => 'секретный-токен']))->status());

    Config::set('metrics.token', '');
});

test('выключенные метрики отвечают 404', function (): void {
    Config::set('metrics.enabled', false);

    assertSame(404, (new ApiKernel())->handle(httpRequest('GET', '/metrics'))->status());

    Config::set('metrics.enabled', true);
});

test('упавшая группа метрик не роняет весь ответ', function (): void {
    Config::set('metrics.enabled', true);
    Config::set('metrics.token', '');

    // Повторяем случай «код приехал раньше миграции»: таблицы для группы ещё нет.
    // Прячем её на время теста и возвращаем сразу после
    $db = Mailer\Storage\Database::instance();
    $db->execute('ALTER TABLE suppressions RENAME TO suppressions_hidden');

    try {
        $body = (new ApiKernel())->handle(httpRequest('GET', '/metrics'))->body();
    } finally {
        $db->execute('ALTER TABLE suppressions_hidden RENAME TO suppressions');
    }

    assertContains("mailer_up 1", $body, 'база на месте — сервис живой');
    assertContains('mailer_messages{status="queued"}', $body, 'остальные метрики должны собраться');
    assertContains('mailer_metrics_failed 1', $body, 'о пропавшей группе говорит отдельная метрика');
    assertNotContains('mailer_suppressions{', $body);
});
