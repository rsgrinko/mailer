<?php

declare(strict_types=1);

/**
 * Простой запускальщик тестов: php tests/run.php (или php bin/mailer test).
 *
 * Никакого PHPUnit — тесты объявляются функцией test(), проверки делаются
 * функциями assertTrue/assertSame и им подобными. Возвращает 0, если всё хорошо.
 */

if (!defined('MAILER_ROOT')) {
    require dirname(__DIR__) . '/bootstrap.php';
}

use Mailer\Storage\Database;
use Mailer\Storage\Migrator;
use Mailer\Support\Config;

// Тесты работают на отдельной базе в памяти — настоящие данные не трогаем
Config::set('db.driver', 'sqlite');
Config::set('db.sqlite.path', ':memory:');
Config::set('log.level', 'error');

Database::setInstance(new Database([
    'driver' => 'sqlite',
    'sqlite' => ['path' => ':memory:'],
]));

(new Migrator())->run();

$GLOBALS['tests']  = [];
$GLOBALS['failed']  = 0;
$GLOBALS['passed']  = 0;
$GLOBALS['skipped'] = 0;

/**
 * Объявить тест.
 */
function test(string $name, callable $body): void
{
    $GLOBALS['tests'][] = ['name' => $name, 'body' => $body];
}

/**
 * Ошибка проверки.
 */
class TestFailure extends RuntimeException
{
}

/**
 * Тест нельзя выполнить в этом окружении (например, нет нужного расширения).
 */
class TestSkipped extends RuntimeException
{
}

/**
 * Пропустить тест с пояснением.
 */
function skipTest(string $reason): never
{
    throw new TestSkipped($reason);
}

function assertTrue(bool $value, string $message = 'ожидалось true'): void
{
    if (!$value) {
        throw new TestFailure($message);
    }
}

function assertFalse(bool $value, string $message = 'ожидалось false'): void
{
    assertTrue(!$value, $message);
}

function assertSame(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new TestFailure(
            ($message !== '' ? $message . ': ' : '')
            . 'ожидалось ' . var_export($expected, true) . ', получено ' . var_export($actual, true)
        );
    }
}

function assertContains(string $needle, string $haystack, string $message = ''): void
{
    if (!str_contains($haystack, $needle)) {
        throw new TestFailure(
            ($message !== '' ? $message . ': ' : '') . 'в строке нет фрагмента «' . $needle . '»'
        );
    }
}

function assertNotContains(string $needle, string $haystack, string $message = ''): void
{
    if (str_contains($haystack, $needle)) {
        throw new TestFailure(
            ($message !== '' ? $message . ': ' : '') . 'в строке не должно быть фрагмента «' . $needle . '»'
        );
    }
}

/**
 * Проверяет, что код бросает исключение.
 */
function assertThrows(callable $callback, string $message = 'ожидалось исключение'): Throwable
{
    try {
        $callback();
    } catch (Throwable $e) {
        return $e;
    }

    throw new TestFailure($message);
}

// Подключаем файлы с тестами
foreach (glob(__DIR__ . '/*Test.php') ?: [] as $file) {
    require $file;
}

echo 'Запускаю тестов: ' . count($GLOBALS['tests']) . PHP_EOL . PHP_EOL;

foreach ($GLOBALS['tests'] as $test) {
    try {
        ($test['body'])();
        $GLOBALS['passed']++;
        echo '  ok    ' . $test['name'] . PHP_EOL;
    } catch (TestSkipped $e) {
        $GLOBALS['skipped']++;
        echo '  пропуск ' . $test['name'] . ' (' . $e->getMessage() . ')' . PHP_EOL;
    } catch (Throwable $e) {
        $GLOBALS['failed']++;
        echo '  ОШИБКА ' . $test['name'] . PHP_EOL;
        echo '         ' . $e->getMessage() . PHP_EOL;

        if (!$e instanceof TestFailure) {
            echo '         ' . get_class($e) . ' в ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL;
        }
    }
}

echo PHP_EOL . 'Успешно: ' . $GLOBALS['passed']
    . ', с ошибками: ' . $GLOBALS['failed']
    . ', пропущено: ' . $GLOBALS['skipped'] . PHP_EOL;

return $GLOBALS['failed'] > 0 ? 1 : 0;
