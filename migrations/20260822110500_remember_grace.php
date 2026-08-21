<?php

declare(strict_types=1);

namespace Mailer\Migrations;

use Mailer\Storage\Migration;
use Mailer\Storage\Schema\Blueprint;

/**
 * Окно снисхождения для токена «запомнить меня».
 *
 * Браузер шлёт запросы параллельно, и все они приходят с одной и той же кукой.
 * Первый успевает сменить validator, остальные приходят со старым — а старый
 * validator считается признаком кражи и гасит токен целиком. Человека при этом
 * просто выкидывает из панели на ровном месте.
 *
 * Поэтому прежний validator хранится ещё несколько секунд после смены: свои
 * параллельные запросы проходят, а настоящая кража (старая кука через час) —
 * по-прежнему гасит токен.
 */
final class RememberGrace extends Migration
{
    public function up(): void
    {
        $this->table('remember_tokens', function (Blueprint $table) {
            $table->string('previous_hash', 64)->nullable();
            $table->dateTime('rotated_at')->nullable();
        });
    }

    public function down(): void
    {
        $this->table('remember_tokens', function (Blueprint $table) {
            $table->dropColumn('previous_hash');
            $table->dropColumn('rotated_at');
        });
    }
}
