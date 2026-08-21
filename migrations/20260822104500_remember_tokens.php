<?php

declare(strict_types=1);

namespace Mailer\Migrations;

use Mailer\Storage\Migration;
use Mailer\Storage\Schema\Blueprint;

/**
 * Галка «запомнить меня» на входе в панель.
 *
 * Хранить в куке логин с паролем нельзя, поэтому кука — это пара «selector:validator»:
 * по первой половине запись находится, вторая сверяется с хешем. Украденную куку
 * так нельзя подобрать перебором по базе, а сама база не даёт войти под пользователем.
 */
final class RememberTokens extends Migration
{
    public function up(): void
    {
        $this->create('remember_tokens', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id');
            $table->string('selector', 32);
            $table->string('token_hash', 64);
            $table->string('ip', 45)->nullable();
            $table->dateTime('expires_at');
            $table->dateTime('last_used_at')->nullable();
            $table->dateTime('created_at');

            // Селектор ищется на каждом запросе без сессии — и он же должен быть один
            $table->unique('idx_remember_selector', 'selector');
            // Чужие токены гасятся пачкой: смена пароля, отключение пользователя
            $table->index('idx_remember_user', 'user_id');
            // Просроченные чистит воркер
            $table->index('idx_remember_expires', 'expires_at');
        });
    }

    public function down(): void
    {
        $this->drop('remember_tokens');
    }
}
