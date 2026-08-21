<?php

declare(strict_types=1);

namespace Mailer\Migrations;

use Mailer\Storage\Migration;
use Mailer\Storage\Schema\Blueprint;

/**
 * Начальная схема сервиса.
 */
final class Init extends Migration
{
    public function up(): void
    {
        // Проекты — это клиенты нашего API. У каждого свой ключ и свои лимиты.
        $this->create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('api_key_prefix', 32);
            $table->string('api_key_hash');
            $table->integer('transport_id')->nullable();
            $table->string('default_from_email')->nullable();
            $table->string('default_from_name')->nullable();
            $table->integer('rate_limit_hour')->default(0);
            $table->integer('rate_limit_day')->default(0);
            $table->string('webhook_url', 500)->nullable();
            $table->string('webhook_secret')->nullable();
            $table->integer('active')->default(1);
            $table->dateTime('created_at');
            $table->dateTime('updated_at');

            $table->unique('idx_projects_name', 'name');
            $table->unique('idx_projects_prefix', 'api_key_prefix');
        });

        // Транспорты — способы отправки: smtp, sendmail, log, null, failover, roundrobin.
        $this->create('transports', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type', 32);
            $table->text('settings');
            $table->string('from_email')->nullable();
            $table->string('from_name')->nullable();
            $table->integer('priority')->default(100);
            $table->integer('daily_limit')->default(0);
            $table->integer('is_default')->default(0);
            $table->integer('active')->default(1);
            $table->dateTime('last_used_at')->nullable();
            $table->text('last_error')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');

            $table->unique('idx_transports_name', 'name');
        });

        // Письма. Тут же лежит история попыток отправки.
        $this->create('messages', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 36);
            $table->integer('project_id')->nullable();
            $table->integer('transport_id')->nullable();
            $table->string('transport_used')->nullable();
            $table->string('status', 20)->default('queued');
            $table->integer('priority')->default(100);
            $table->string('source', 20)->default('api');
            $table->string('subject', 500)->nullable();
            $table->string('from_email')->nullable();
            $table->string('from_name')->nullable();
            $table->string('reply_to')->nullable();
            $table->text('to_json')->nullable();
            $table->text('cc_json')->nullable();
            $table->text('bcc_json')->nullable();
            $table->text('headers_json')->nullable();
            $table->longText('text_body')->nullable();
            $table->longText('html_body')->nullable();
            $table->text('attachments_json')->nullable();
            $table->longText('raw_mime')->nullable();
            $table->string('envelope_from')->nullable();
            $table->text('envelope_to')->nullable();
            $table->string('template')->nullable();
            $table->text('template_data')->nullable();
            $table->text('meta')->nullable();
            $table->string('tag')->nullable();
            $table->string('idempotency_key')->nullable();
            $table->integer('size')->default(0);
            $table->integer('attempts')->default(0);
            $table->integer('max_attempts')->default(5);
            $table->dateTime('available_at')->nullable();
            $table->dateTime('locked_at')->nullable();
            $table->string('locked_by', 64)->nullable();
            $table->text('last_error')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');

            $table->unique('idx_messages_uuid', 'uuid');
            $table->index('idx_messages_queue', 'status, available_at');
            $table->index('idx_messages_project', 'project_id, created_at');
            $table->index('idx_messages_created', 'created_at');
            $table->index('idx_messages_idem', 'project_id, idempotency_key');
        });

        // Что происходило с письмом: принято, попытка, ошибка, отправлено.
        $this->create('message_events', function (Blueprint $table) {
            $table->id();
            $table->integer('message_id');
            $table->string('type', 32);
            $table->text('message')->nullable();
            $table->text('meta')->nullable();
            $table->dateTime('created_at');

            $table->index('idx_events_message', 'message_id, id');
        });

        // Шаблоны писем с переменными.
        $this->create('templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('subject', 500)->nullable();
            $table->longText('html')->nullable();
            $table->longText('text')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');

            $table->unique('idx_templates_name', 'name');
        });

        // Очередь вебхуков: сообщаем проекту, что случилось с письмом.
        $this->create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->integer('message_id')->nullable();
            $table->integer('project_id')->nullable();
            $table->string('url', 500);
            $table->string('event', 32);
            $table->text('payload');
            $table->string('status', 20)->default('queued');
            $table->integer('attempts')->default(0);
            $table->integer('response_code')->nullable();
            $table->text('last_error')->nullable();
            $table->dateTime('available_at')->nullable();
            $table->dateTime('delivered_at')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');

            $table->index('idx_webhooks_queue', 'status, available_at');
        });

        // Счётчики для лимитов отправки.
        $this->create('counters', function (Blueprint $table) {
            $table->string('counter_key')->primary();
            $table->integer('value')->default(0);
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('updated_at');
        });

        // Служебные значения: heartbeat воркера, версия и т.п.
        $this->create('settings', function (Blueprint $table) {
            $table->string('setting_key')->primary();
            $table->text('value')->nullable();
            $table->dateTime('updated_at');
        });
    }

    public function down(): void
    {
        $this->drop('settings');
        $this->drop('counters');
        $this->drop('webhook_deliveries');
        $this->drop('templates');
        $this->drop('message_events');
        $this->drop('messages');
        $this->drop('transports');
        $this->drop('projects');
    }
}
