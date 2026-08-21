<?php

declare(strict_types=1);

/**
 * Простой запускальщик тестов: php tests/run.php (или php bin/mailer test).
 *
 * Никакого PHPUnit — тесты объявляются функцией test(), проверки делаются
 * функциями assertTrue/assertSame и им подобными. Возвращает 0, если всё хорошо.
 *
 * Опции:
 *   --filter=строка     гонять только тесты, у которых строка есть в имени или в имени файла
 *   --stop-on-failure   остановиться на первой ошибке
 *   --shuffle[=seed]    перемешать порядок (ловит тесты, зависящие от соседей)
 *   --slow=ms           с какого времени тест считается медленным (по умолчанию 500)
 *
 * База по умолчанию — SQLite в памяти. Тесты заводят и удаляют записи, поэтому
 * боевую базу из .env они не трогают никогда: MySQL включается только своими
 * настройками TEST_DB_* (см. .env.example), и база в них должна быть отдельной.
 */

if (!defined('MAILER_ROOT')) {
    require dirname(__DIR__) . '/bootstrap.php';
}

use Mailer\Storage\Database;
use Mailer\Storage\Migrator;
use Mailer\Support\Config;
use Mailer\Support\Env;

/** @var array<string, string> Опции запуска: от bin/mailer test или из своего argv */
$options = $GLOBALS['test_options'] ?? [];

if ($options === [] && PHP_SAPI === 'cli') {
    foreach (array_slice($_SERVER['argv'] ?? [], 1) as $argument) {
        if (str_starts_with($argument, '--')) {
            $argument = substr($argument, 2);
            [$name, $value] = str_contains($argument, '=') ? explode('=', $argument, 2) : [$argument, '1'];
            $options[$name] = $value;
        }
    }
}

$GLOBALS['test_options'] = $options;

Config::set('log.level', 'error');

/**
 * Настройки отдельной базы MySQL для тестов — или null, если её не завели.
 *
 * Боевые DB_* сюда не годятся ни при каких условиях: тесты заводят и удаляют
 * записи, в том числе всех пользователей панели. Нужна своя пустая база в
 * TEST_DB_*, и совпадение с боевой считается опиской.
 *
 * @return array<string, mixed>|null
 */
function testMysqlConfig(): ?array
{
    if (Env::string('TEST_DB_DRIVER', 'sqlite') !== 'mysql') {
        return null;
    }

    $database = Env::string('TEST_DB_DATABASE', '');

    if ($database === '') {
        return null;
    }

    $config = [
        'host'     => Env::string('TEST_DB_HOST', '127.0.0.1'),
        'port'     => Env::int('TEST_DB_PORT', 3306),
        'database' => $database,
        'username' => Env::string('TEST_DB_USERNAME', 'root'),
        'password' => Env::string('TEST_DB_PASSWORD', ''),
        'charset'  => Env::string('TEST_DB_CHARSET', 'utf8mb4'),
    ];

    if (
        $config['database'] === Env::string('DB_DATABASE', '')
        && $config['host'] === Env::string('DB_HOST', '127.0.0.1')
    ) {
        fwrite(STDERR, 'TEST_DB_* указывает на боевую базу — тесты её затрут. Заведите отдельную.' . PHP_EOL);

        exit(1);
    }

    return $config;
}

// Тесты работают на своей базе — настоящие данные не трогаем
$mysql = testMysqlConfig();

if ($mysql !== null) {
    Config::set('db.driver', 'mysql');
    Config::set('db.mysql', $mysql);

    Database::setInstance(new Database(['driver' => 'mysql', 'mysql' => $mysql]));
} else {
    Config::set('db.driver', 'sqlite');
    Config::set('db.sqlite.path', ':memory:');

    Database::setInstance(new Database([
        'driver' => 'sqlite',
        'sqlite' => ['path' => ':memory:'],
    ]));
}

(new Migrator())->run();

$GLOBALS['tests']    = [];
$GLOBALS['cleanups'] = [];
$GLOBALS['failed']   = 0;
$GLOBALS['passed']   = 0;
$GLOBALS['skipped']  = 0;

/**
 * Объявить тест. Файл запоминаем, чтобы работал --filter=QueueTest.
 */
function test(string $name, callable $body): void
{
    $GLOBALS['tests'][] = [
        'name' => $name,
        'body' => $body,
        'file' => basename($GLOBALS['test_file'] ?? ''),
    ];
}

/**
 * Отложить уборку до конца прогона: удалить заведённые тестом записи, вернуть настройку.
 *
 * Уборщики зовутся в обратном порядке и после всех тестов — даже если тест упал.
 * Так «прибираем за собой» перестаёт быть отдельным тестом, который обязан идти последним.
 */
function afterTests(callable $callback): void
{
    $GLOBALS['cleanups'][] = $callback;
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

function assertNull(mixed $value, string $message = 'ожидался null'): void
{
    if ($value !== null) {
        throw new TestFailure($message . ', получено ' . var_export($value, true));
    }
}

/**
 * Значение есть — и заодно возвращается, чтобы не писать проверку и выборку дважды.
 */
function assertNotNull(mixed $value, string $message = 'значение не должно быть null'): mixed
{
    if ($value === null) {
        throw new TestFailure($message);
    }

    return $value;
}

function assertCount(int $expected, array|Countable $items, string $message = ''): void
{
    $actual = count($items);

    if ($actual !== $expected) {
        throw new TestFailure(
            ($message !== '' ? $message . ': ' : '') . 'ожидалось записей ' . $expected . ', получено ' . $actual
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

function assertMatches(string $pattern, string $subject, string $message = ''): void
{
    if (preg_match($pattern, $subject) !== 1) {
        throw new TestFailure(
            ($message !== '' ? $message . ': ' : '') . 'строка не подошла под ' . $pattern
            . ': ' . mb_substr($subject, 0, 200)
        );
    }
}

/**
 * Код ответа HTTP. При расхождении показывает начало тела — по нему сразу видно,
 * что вернулось на самом деле: страница ошибки, редирект или JSON с описанием.
 */
function assertStatus(int $expected, Mailer\Http\Response $response, string $message = ''): void
{
    if ($response->status() === $expected) {
        return;
    }

    $body = trim(strip_tags($response->body()));
    $body = preg_replace('/\s+/u', ' ', $body) ?? '';

    throw new TestFailure(
        ($message !== '' ? $message . ': ' : '')
        . 'ожидался код ' . $expected . ', получен ' . $response->status()
        . ($body === '' ? '' : ' — ' . mb_substr($body, 0, 200))
    );
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

/**
 * Где сработала проверка: первый кадр стека вне самого раннера.
 */
function testLocation(Throwable $e): string
{
    $frames = array_merge([['file' => $e->getFile(), 'line' => $e->getLine()]], $e->getTrace());

    foreach ($frames as $frame) {
        $file = (string) ($frame['file'] ?? '');

        if ($file !== '' && basename($file) !== 'run.php') {
            return basename($file) . ':' . (int) ($frame['line'] ?? 0);
        }
    }

    return basename((string) $e->getFile()) . ':' . $e->getLine();
}

// Предупреждение PHP — это ошибка теста: «Undefined array key» во вьюхе не должен
// молча уезжать в вывод. Подавленное через @ пропускаем — так задумано автором кода.
error_reporting(E_ALL);

set_error_handler(static function (int $level, string $message, string $file, int $line): bool {
    if ((error_reporting() & $level) === 0) {
        return false;
    }

    $names = [
        E_WARNING       => 'предупреждение',
        E_NOTICE        => 'замечание',
        E_DEPRECATED    => 'устаревшее',
        E_USER_WARNING  => 'предупреждение',
        E_USER_NOTICE   => 'замечание',
        E_USER_ERROR    => 'ошибка',
        E_RECOVERABLE_ERROR => 'ошибка',
    ];

    throw new TestFailure(
        ($names[$level] ?? 'ошибка') . ' PHP: ' . $message . ' (' . basename($file) . ':' . $line . ')'
    );
});

// Подключаем файлы с тестами
foreach (glob(__DIR__ . '/*Test.php') ?: [] as $file) {
    $GLOBALS['test_file'] = $file;

    require $file;
}

unset($GLOBALS['test_file']);

$filter = (string) ($options['filter'] ?? '');

if ($filter !== '') {
    $GLOBALS['tests'] = array_values(array_filter(
        $GLOBALS['tests'],
        static fn (array $test): bool => mb_stripos($test['name'], $filter) !== false
            || stripos($test['file'], $filter) !== false
    ));
}

if (isset($options['shuffle'])) {
    $seed = $options['shuffle'] === '1' ? random_int(1, 999999) : (int) $options['shuffle'];

    mt_srand($seed);

    $order = $GLOBALS['tests'];
    shuffle($order);
    $GLOBALS['tests'] = $order;

    echo 'Порядок перемешан, seed=' . $seed . ' (повторить: --shuffle=' . $seed . ')' . PHP_EOL;
}

$stopOnFailure = isset($options['stop-on-failure']);
$slow          = (int) ($options['slow'] ?? 500);

echo 'Запускаю тестов: ' . count($GLOBALS['tests']) . PHP_EOL . PHP_EOL;

$started = microtime(true);

foreach ($GLOBALS['tests'] as $test) {
    $at = microtime(true);

    try {
        ($test['body'])();
        $GLOBALS['passed']++;

        $spent = (int) round((microtime(true) - $at) * 1000);

        echo '  ok    ' . $test['name'] . ($spent >= $slow ? ' (' . $spent . ' мс)' : '') . PHP_EOL;
    } catch (TestSkipped $e) {
        $GLOBALS['skipped']++;
        echo '  пропуск ' . $test['name'] . ' (' . $e->getMessage() . ')' . PHP_EOL;
    } catch (Throwable $e) {
        $GLOBALS['failed']++;
        echo '  ОШИБКА ' . $test['name'] . PHP_EOL;
        echo '         ' . $e->getMessage() . PHP_EOL;
        echo '         ' . ($e instanceof TestFailure ? '' : get_class($e) . ' в ') . testLocation($e) . PHP_EOL;

        if ($stopOnFailure) {
            echo PHP_EOL . 'Остановлено на первой ошибке (--stop-on-failure)' . PHP_EOL;

            break;
        }
    }
}

// Уборка идёт после всех тестов и в обратном порядке: сначала убирается то, что завели позже
foreach (array_reverse($GLOBALS['cleanups']) as $cleanup) {
    try {
        $cleanup();
    } catch (Throwable $e) {
        echo '  уборка не удалась: ' . $e->getMessage() . PHP_EOL;
    }
}

restore_error_handler();

echo PHP_EOL . 'Успешно: ' . $GLOBALS['passed']
    . ', с ошибками: ' . $GLOBALS['failed']
    . ', пропущено: ' . $GLOBALS['skipped']
    . ', времени: ' . number_format(microtime(true) - $started, 1, ',', ' ') . ' с' . PHP_EOL;

return $GLOBALS['failed'] > 0 ? 1 : 0;
