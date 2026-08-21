<?php

declare(strict_types=1);

namespace Mailer\Migrations;

use Mailer\Storage\Migration;
use Mailer\Storage\Schema\Blueprint;

/**
 * Пользователи панели. Появились позже остальных таблиц, поэтому отдельной миграцией.
 */
final class Users extends Migration
{
    public function up(): void
    {
        $this->create('users', function (Blueprint $table) {
            $table->id();
            $table->string('login');
            $table->string('name')->nullable();
            $table->string('password_hash');
            $table->integer('active')->default(1);
            $table->dateTime('last_login_at')->nullable();
            $table->string('last_login_ip', 64)->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');

            $table->unique('idx_users_login', 'login');
        });
    }

    public function down(): void
    {
        $this->drop('users');
    }
}
