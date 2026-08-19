<?php

declare(strict_types=1);

namespace Mailer\Console\Commands;

use Mailer\Console\Command;
use Mailer\Security\Crypto;

/**
 * сгенерировать ключ шифрования для .env.
 */
final class AppKeyCommand extends Command
{
    public function name(): string
    {
        return 'app:key';
    }

    public function description(): string
    {
        return 'сгенерировать ключ шифрования для .env';
    }

    public function usage(): string
    {
        return 'app:key';
    }

    public function run(): int
    {

        $key = Crypto::generateKey();

        $this->line('Добавьте в .env строку:');
        $this->line('APP_KEY=' . $key);
        $this->line('');
        $this->line('Если в базе уже есть транспорты с паролями — после смены ключа их надо задать заново.');

        return 0;
    
    }
}
