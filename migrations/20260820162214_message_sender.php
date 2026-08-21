<?php

declare(strict_types=1);

namespace Mailer\Migrations;

use Mailer\Storage\Migration;
use Mailer\Storage\Schema\Blueprint;

/**
 * Адрес, с которого письмо ушло на самом деле. В `from_email` лежит то, что прислал
 * клиент, а транспорт с `force_from` подменяет отправителя уже на отправке —
 * без отдельной колонки в карточке письма не понять, почему адрес не тот.
 */
final class MessageSender extends Migration
{
    public function up(): void
    {
        $this->table('messages', function (Blueprint $table) {
            $table->string('sender_used')->nullable();
        });
    }

    public function down(): void
    {
        $this->table('messages', function (Blueprint $table) {
            $table->dropColumn('sender_used');
        });
    }
}
