<?php

declare(strict_types=1);

namespace Mailer\Migrations;

use Mailer\Storage\Migration;
use Mailer\Storage\Schema\Blueprint;

/**
 * Отписка одной кнопкой у проекта. Ноль — заголовки отписки в его письма не ставим:
 * служебному письму про сброс пароля кнопка «отписаться» ни к чему, а массовой
 * рассылке без неё закроют дорогу Gmail и Mail.ru.
 */
final class Unsubscribe extends Migration
{
    public function up(): void
    {
        $this->table('projects', function (Blueprint $table) {
            $table->integer('unsubscribe')->default(0);
        });
    }

    public function down(): void
    {
        $this->table('projects', function (Blueprint $table) {
            $table->dropColumn('unsubscribe');
        });
    }
}
