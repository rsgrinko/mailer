<?php

declare(strict_types=1);

namespace Mailer\Migrations;

use Mailer\Storage\Migration;
use Mailer\Storage\Schema\Blueprint;

/**
 * Подписки на события вместо одного адреса у проекта и подробности доставок.
 *
 * Раньше вебхук был один на проект (колонки projects.webhook_url и
 * webhook_secret) и слал ровно два события. Теперь адресов может быть
 * сколько угодно, у каждого свой секрет и свой набор событий.
 *
 * Старые колонки в projects не трогаем: база боевая, а данные из них
 * переезжают копией. Перенесённая подписка получает payload_version = 1 —
 * прежний плоский формат тела и прежние имена событий, чтобы у тех, кто уже
 * принимает наши вебхуки, ничего не сломалось.
 *
 * Секрет переносится как есть, без шифрования: Crypto::decrypt() понимает
 * и незашифрованное значение. Перезаписанный в панели секрет ляжет уже
 * зашифрованным.
 */
final class WebhookSubscriptions extends Migration
{
    public function up(): void
    {
        $this->create('project_webhooks', function (Blueprint $table) {
            $table->id();
            $table->integer('project_id');
            $table->string('name')->nullable();
            $table->string('url', 500);
            $table->string('secret', 500)->nullable();
            $table->text('events')->nullable();
            $table->integer('payload_version')->default(2);
            $table->integer('active')->default(1);
            $table->integer('failures')->default(0);
            $table->string('last_status', 20)->nullable();
            $table->text('last_error')->nullable();
            $table->dateTime('last_delivery_at')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');

            $table->index('idx_project_webhooks_project', 'project_id, active');
        });

        // Прежние вебхуки проектов переезжают как есть. Проекты, у которых подписка уже
        // есть, пропускаем: миграция может доезжать после обрыва, а дубль подписки —
        // это второй такой же запрос в чужой приёмник на каждое событие.
        // Готовые подписки берём подзапросом в FROM: MySQL не даёт читать целевую
        // таблицу INSERT напрямую, а производную таблицу принимает.
        $this->statement(
            "INSERT INTO project_webhooks (project_id, name, url, secret, events, payload_version, active, created_at, updated_at)
                SELECT p.id, :name, p.webhook_url, p.webhook_secret, NULL, 1, 1, :created_at, :updated_at
                FROM projects p
                LEFT JOIN (SELECT DISTINCT project_id FROM project_webhooks) w ON w.project_id = p.id
                WHERE p.webhook_url IS NOT NULL AND p.webhook_url <> '' AND w.project_id IS NULL",
            [
                'name'       => 'Вебхук проекта',
                'created_at' => $this->now(),
                'updated_at' => $this->now(),
            ]
        );

        // Доставка: что именно ушло и что ответили. Без этого отладить вебхук нечем
        $this->table('webhook_deliveries', function (Blueprint $table) {
            $table->string('uuid', 36)->nullable();
            $table->integer('subscription_id')->nullable();
            $table->text('request_headers')->nullable();
            $table->text('response_headers')->nullable();
            $table->longText('response_body')->nullable();
            $table->integer('duration_ms')->nullable();

            $table->index('idx_webhooks_uuid', 'uuid');
            $table->index('idx_webhooks_event', 'event, id');
            $table->index('idx_webhooks_subscription', 'subscription_id, id');
        });
    }

    public function down(): void
    {
        $this->table('webhook_deliveries', function (Blueprint $table) {
            $table->dropIndex('idx_webhooks_uuid');
            $table->dropIndex('idx_webhooks_event');
            $table->dropIndex('idx_webhooks_subscription');
            $table->dropColumn('uuid');
            $table->dropColumn('subscription_id');
            $table->dropColumn('request_headers');
            $table->dropColumn('response_headers');
            $table->dropColumn('response_body');
            $table->dropColumn('duration_ms');
        });

        $this->drop('project_webhooks');
    }
}
