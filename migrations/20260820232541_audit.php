<?php

declare(strict_types=1);

namespace Mailer\Migrations;

use Mailer\Storage\Migration;
use Mailer\Storage\Schema\Blueprint;

/**
 * Журнал действий в панели. Кто, что и над какой записью сделал — по логам такое
 * не восстановить: там нет ни пользователя, ни того, что именно поменялось.
 *
 * Логин пишем строкой рядом с id: пользователя могут удалить, а запись в журнале
 * должна остаться читаемой.
 */
final class Audit extends Migration
{
    public function up(): void
    {
        $this->create('audit_log', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id')->default(0);
            $table->string('user_login')->nullable();
            $table->string('action', 64);
            $table->string('entity', 64);
            $table->integer('entity_id')->nullable();
            $table->text('summary')->nullable();
            $table->string('ip', 64)->nullable();
            $table->dateTime('created_at');

            $table->index('idx_audit_created', 'created_at');
            $table->index('idx_audit_user', 'user_id, created_at');
            $table->index('idx_audit_entity', 'entity, entity_id');
        });
    }

    public function down(): void
    {
        $this->drop('audit_log');
    }
}
