<?php

/**
 * Страница настроек: адрес сервиса, ключ, режим отправки, проверки
 * и тестовое письмо.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MailerService_Admin
{
    /** @var MailerService_API */
    protected $api;

    /** @var array настройки плагина */
    protected $options;

    /** @var string слаг страницы настроек */
    protected $slug = 'mailerservice';

    public function __construct(MailerService_API $api, array $options)
    {
        $this->api     = $api;
        $this->options = $options;
    }

    /**
     * Хуки страницы настроек.
     */
    public function register()
    {
        add_action('admin_menu', array($this, 'addMenu'));
        add_action('admin_init', array($this, 'registerSettings'));
        add_action('admin_post_mailerservice_health', array($this, 'actionHealth'));
        add_action('admin_post_mailerservice_test', array($this, 'actionTest'));
        add_action('admin_post_mailerservice_log_clear', array($this, 'actionLogClear'));
        add_action('admin_notices', array($this, 'showNotice'));
    }

    /**
     * Пункт меню «Параметры -> Сервис рассылки».
     */
    public function addMenu()
    {
        add_options_page(
            'Сервис рассылки',
            'Сервис рассылки',
            'manage_options',
            $this->slug,
            array($this, 'renderPage')
        );
    }

    /**
     * Регистрация настроек.
     */
    public function registerSettings()
    {
        register_setting('mailerservice', 'mailerservice_options', array($this, 'sanitize'));
        add_settings_section('mailerservice_main', '', '__return_null', $this->slug);

        add_settings_field('mailerservice_url', 'Адрес сервиса', array($this, 'fieldUrl'), $this->slug, 'mailerservice_main');
        add_settings_field('mailerservice_apikey', 'API-ключ', array($this, 'fieldApikey'), $this->slug, 'mailerservice_main');
        add_settings_field('mailerservice_mode', 'Режим отправки', array($this, 'fieldMode'), $this->slug, 'mailerservice_main');
        add_settings_field('mailerservice_transport', 'Транспорт', array($this, 'fieldTransport'), $this->slug, 'mailerservice_main');
        add_settings_field('mailerservice_tag', 'Метка писем', array($this, 'fieldTag'), $this->slug, 'mailerservice_main');
        add_settings_field('mailerservice_from_email', 'Отправитель (Email)', array($this, 'fieldFromEmail'), $this->slug, 'mailerservice_main');
        add_settings_field('mailerservice_from_name', 'Отправитель (Имя)', array($this, 'fieldFromName'), $this->slug, 'mailerservice_main');
        add_settings_field('mailerservice_timeout', 'Таймаут', array($this, 'fieldTimeout'), $this->slug, 'mailerservice_main');
        add_settings_field('mailerservice_fallback', 'Запасная отправка', array($this, 'fieldFallback'), $this->slug, 'mailerservice_main');
        add_settings_field('mailerservice_logging', 'Журнал диагностики', array($this, 'fieldLogging'), $this->slug, 'mailerservice_main');
    }

    /**
     * Санитизация значений из формы.
     *
     * @param mixed $input
     * @return array
     */
    public function sanitize($input)
    {
        $options = is_array($input) ? $input : array();

        $url = isset($options['url']) ? trim((string) $options['url']) : '';
        if ($url !== '' && !preg_match('#^https?://#i', $url)) {
            $url = 'http://' . $url;
        }

        $clean = array(
            'url'       => esc_url_raw($url),
            'apikey'    => isset($options['apikey']) ? sanitize_text_field((string) $options['apikey']) : '',
            'mode'      => (isset($options['mode']) && $options['mode'] === 'sync') ? 'sync' : 'queue',
            'transport' => isset($options['transport']) ? sanitize_text_field((string) $options['transport']) : '',
            'tag'       => isset($options['tag']) ? sanitize_text_field((string) $options['tag']) : 'wordpress',
            'from_email' => isset($options['from_email']) ? sanitize_email((string) $options['from_email']) : '',
            'from_name'  => isset($options['from_name']) ? sanitize_text_field((string) $options['from_name']) : '',
            'timeout'   => isset($options['timeout']) ? min(120, max(1, (int) $options['timeout'])) : 10,
            'fallback'  => !empty($options['fallback']) ? 1 : 0,
            'logging'   => !empty($options['logging']) ? 1 : 0,
        );

        return $clean;
    }

    /**
     * Проверка: ключ проекта и состояние сервиса.
     */
    public function actionHealth()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Недостаточно прав');
        }
        check_admin_referer('mailerservice_health');

        if (!$this->api->ping()) {
            $this->notice(array('ok' => false, 'message' => 'Не удалось связаться с сервисом: ' . $this->api->getError()));
        } else {
            $health = $this->api->health();
            if ($health === null) {
                $this->notice(array('ok' => false, 'message' => 'Проверка не удалась: ' . $this->api->getError()));
            } else {
                $this->notice(array('ok' => true, 'message' => $this->healthSummary($health)));
            }
        }

        wp_safe_redirect(add_query_arg(array('page' => $this->slug), admin_url('options-general.php')));
        exit;
    }

    /**
     * Тестовое письмо: уходит обычным путём wp_mail, то есть проверяется
     * вся цепочка вместе с плагином.
     */
    public function actionTest()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Недостаточно прав');
        }
        check_admin_referer('mailerservice_test');

        $to = isset($_POST['mailerservice_to']) ? sanitize_email(wp_unslash($_POST['mailerservice_to'])) : '';

        if (!is_email($to)) {
            $this->notice(array('ok' => false, 'message' => 'Некорректный адрес получателя'));
        } else {
            $sent = wp_mail(
                $to,
                'Проверка отправки почты',
                "Это тестовое письмо из WordPress.\nЕсли вы его читаете, отправка через сервис рассылки работает."
            );

            $this->notice(array(
                'ok'      => (bool) $sent,
                'message' => $sent ? 'Письмо принято сервисом' : 'Отправить не удалось, смотрите журнал ошибок WordPress',
            ));
        }

        wp_safe_redirect(add_query_arg(array('page' => $this->slug), admin_url('options-general.php')));
        exit;
    }

    /**
     * Очистка журнала диагностики.
     */
    public function actionLogClear()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Недостаточно прав');
        }
        check_admin_referer('mailerservice_log_clear');

        $mailer = new MailerService_Mailer($this->api, $this->options);
        $mailer->clearLog();

        $this->notice(array('ok' => true, 'message' => 'Журнал очищен'));

        wp_safe_redirect(add_query_arg(array('page' => $this->slug), admin_url('options-general.php')));
        exit;
    }

    /**
     * Краткое состояние сервиса из ответа /api/v1/health.
     *
     * @param array $health
     * @return string
     */
    protected function healthSummary(array $health)
    {
        $checks = isset($health['checks']) && is_array($health['checks']) ? $health['checks'] : array();
        $queue  = isset($checks['queue']) && is_array($checks['queue']) ? $checks['queue'] : array();
        $worker = isset($checks['worker']) && is_array($checks['worker']) ? $checks['worker'] : array();

        $lines = array();
        $lines[] = 'Статус: ' . esc_html(isset($health['status']) ? (string) $health['status'] : '?');
        $lines[] = 'База данных: ' . (!empty($checks['database']['ok']) ? 'в порядке' : 'проблема');
        $lines[] = sprintf(
            'Очередь: ждут %d, отложено %d, с ошибкой %d',
            (int) (isset($queue['ready']) ? $queue['ready'] : 0),
            (int) (isset($queue['delayed']) ? $queue['delayed'] : 0),
            (int) (isset($queue['failed']) ? $queue['failed'] : 0)
        );
        $lines[] = !empty($worker['ok'])
            ? 'Воркер: в порядке, последний раз отвечал ' . esc_html(isset($worker['last_seen']) ? (string) $worker['last_seen'] : '?')
            : 'Воркер: не отвечал';

        return implode('<br>', $lines);
    }

    /**
     * Сохранение уведомления для страницы настроек.
     *
     * @param array $data
     */
    protected function notice(array $data)
    {
        set_transient('mailerservice_notice', $data, 60);
    }

    /**
     * Вывод уведомления после проверки или тестового письма.
     */
    public function showNotice()
    {
        $screen = get_current_screen();
        if ($screen === null || $screen->id !== 'settings_page_' . $this->slug) {
            return;
        }

        $notice = get_transient('mailerservice_notice');
        if (!is_array($notice)) {
            return;
        }
        delete_transient('mailerservice_notice');

        $class   = !empty($notice['ok']) ? 'notice-success' : 'notice-error';
        $message = isset($notice['message']) ? $notice['message'] : '';

        echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p>' . wp_kses_post($message) . '</p></div>';
    }

    /**
     * Сама страница настроек.
     */
    public function renderPage()
    {
        ?>
        <div class="wrap">
            <h1>Сервис рассылки</h1>
            <p>Почта WordPress уходит через сервис рассылки по HTTP API вместо mail().</p>

            <?php settings_errors(); ?>

            <h2>Настройки</h2>
            <form action="options.php" method="post">
                <?php settings_fields('mailerservice'); ?>
                <?php do_settings_sections($this->slug); ?>
                <?php submit_button('Сохранить настройки'); ?>
            </form>

            <h2>Состояние сервиса</h2>
            <p>
                <a class="button" href="<?php echo esc_url(admin_url('admin-post.php?action=mailerservice_health&_wpnonce=' . wp_create_nonce('mailerservice_health'))); ?>">Проверить</a>
                — проверка ключа и запрос /api/v1/health.
            </p>

            <h2>Тестовое письмо</h2>
            <p>Письмо уходит обычным путём WordPress (wp_mail), то есть проверяется вся цепочка вместе с плагином.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="mailerservice_test">
                <?php wp_nonce_field('mailerservice_test'); ?>
                <p>
                    <label>Кому <input type="email" name="mailerservice_to" required></label>
                    <?php submit_button('Отправить', 'secondary'); ?>
                </p>
            </form>

            <?php $this->renderDiagnostics(); ?>
        </div>
        <?php
    }

    /**
     * Блок диагностики: окружение, перехватчики почты и журнал событий.
     */
    protected function renderDiagnostics()
    {
        ?>
        <h2>Диагностика</h2>
        <p>Если письма не уходят в очередь сервиса — посмотрите журнал ниже. Пустой журнал означает,
            что фильтр <code>pre_wp_mail</code> не срабатывает вообще: проверьте версию WordPress
            (нужна 5.7+) и другие плагины, которые переопределяют <code>wp_mail</code> или
            перехватывают почту раньше.</p>

        <table class="widefat striped">
            <tbody>
                <?php foreach ($this->environment() as $name => $value) { ?>
                    <tr>
                        <td><strong><?php echo esc_html($name); ?></strong></td>
                        <td><?php echo wp_kses_post($value); ?></td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>

        <h3>Журнал событий (последние <?php echo esc_html(MailerService_Mailer::LOG_LIMIT); ?>)</h3>
        <p>
            <a class="button" href="<?php echo esc_url(admin_url('admin-post.php?action=mailerservice_log_clear&_wpnonce=' . wp_create_nonce('mailerservice_log_clear'))); ?>">Очистить журнал</a>
        </p>
        <?php
        $mailer = new MailerService_Mailer($this->api, $this->options);
        $log    = $mailer->getLog();

        if (empty($log)) {
            echo '<p>Журнал пуст. Отправьте тестовое письмо — и в журнале появится, что случилось с ним.</p>';
        } else {
            echo '<table class="widefat striped">';
            echo '<thead><tr><th>Время</th><th>Событие</th></tr></thead>';
            echo '<tbody>';
            foreach (array_reverse($log) as $entry) {
                $text = isset($entry['message']) ? (string) $entry['message'] : '';
                if (isset($entry['context']) && is_array($entry['context']) && $entry['context'] !== array()) {
                    $text .= ' <code>' . esc_html((string) wp_json_encode($entry['context'], JSON_UNESCAPED_UNICODE)) . '</code>';
                }
                echo '<tr>';
                echo '<td>' . esc_html(isset($entry['time']) ? (string) $entry['time'] : '') . '</td>';
                echo '<td>' . wp_kses_post($text) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
    }

    /**
     * Сведения об окружении для страницы диагностики.
     *
     * @return array
     */
    protected function environment()
    {
        $rows = array();

        $rows['Версия WordPress'] = esc_html(get_bloginfo('version'))
            . (version_compare(get_bloginfo('version'), '5.7', '>=')
                ? ''
                : ' <strong style="color:#b32d2e">(нужна 5.7+, иначе фильтр pre_wp_mail не работает)</strong>');

        $rows['PHP'] = esc_html(PHP_VERSION);

        $rows['wp_mail'] = $this->wpMailOrigin();

        $rows['cURL'] = function_exists('curl_init') ? 'доступен' : 'нет (используется потоковый транспорт WordPress)';
        $rows['allow_url_fopen'] = ini_get('allow_url_fopen') ? 'включён' : 'выключен';

        $url = isset($this->options['url']) ? (string) $this->options['url'] : '';
        $urlValid = $url !== '' && wp_http_validate_url($url) !== false;
        $rows['Адрес сервиса'] = $url !== ''
            ? esc_html($url) . ' <span style="color:' . ($urlValid ? '#00a32a' : '#b32d2e') . '">' . ($urlValid ? '(корректный)' : '(некорректный)') . '</span>'
            : 'не задан';

        $key = isset($this->options['apikey']) ? (string) $this->options['apikey'] : '';
        $rows['API-ключ'] = $key !== ''
            ? 'задан, начинается с <code>' . esc_html(substr($key, 0, 6)) . '…</code>'
            : 'не задан';

        $fromEmail = isset($this->options['from_email']) ? (string) $this->options['from_email'] : '';
        $rows['Отправитель'] = $fromEmail !== ''
            ? esc_html($fromEmail)
            : 'не задан — отправителя возьмёт транспорт сервиса (не WordPress)';

        $rows['Активные плагины'] = $this->activePlugins();

        $pre = $this->hookCallbacks('pre_wp_mail');
        $rows['Перехватчики pre_wp_mail'] = $pre !== array()
            ? implode('<br>', array_map('esc_html', $pre))
            : 'нет';

        $phpm = $this->hookCallbacks('phpmailer_init');
        $rows['Перехватчики phpmailer_init'] = $phpm !== array()
            ? implode('<br>', array_map('esc_html', $phpm))
            : 'нет';

        return $rows;
    }

    /**
     * Откуда определена wp_mail: штатный pluggable.php или переопределил плагин.
     *
     * @return string
     */
    protected function wpMailOrigin()
    {
        try {
            $ref  = new ReflectionFunction('wp_mail');
            $file = $ref->getFileName();
            if (strpos($file, 'pluggable.php') !== false) {
                return 'штатная (wp-includes/pluggable.php)';
            }
            return 'переопределена: <code>' . esc_html($file) . '</code>';
        } catch (ReflectionException $e) {
            return 'не определена';
        }
    }

    /**
     * Список активных плагинов.
     *
     * @return string
     */
    protected function activePlugins()
    {
        $active = get_option('active_plugins', array());
        if (!is_array($active) || $active === array()) {
            return 'нет';
        }

        $names = array();
        foreach ($active as $plugin) {
            if (!function_exists('get_plugin_data')) {
                $names[] = basename($plugin);
                continue;
            }
            $data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin, false, false);
            $names[] = !empty($data['Name']) ? $data['Name'] : basename($plugin);
        }

        return implode('<br>', array_map('esc_html', $names));
    }

    /**
     * Имена функций, подписанных на хук. Показывает, кто ещё трогает почту.
     *
     * @param string $hook
     * @return array
     */
    protected function hookCallbacks($hook)
    {
        if (!isset($GLOBALS['wp_filter'][$hook])) {
            return array();
        }

        $hookObj = $GLOBALS['wp_filter'][$hook];
        if (!is_object($hookObj) || !isset($hookObj->callbacks) || !is_array($hookObj->callbacks)) {
            return array();
        }

        $names = array();
        foreach ($hookObj->callbacks as $priority => $callbacks) {
            foreach ((array) $callbacks as $cb) {
                if (isset($cb['function']) && $cb['function'] !== '__return_null') {
                    $names[] = $this->callbackName($cb['function']) . ' (приоритет ' . $priority . ')';
                }
            }
        }

        return $names;
    }

    /**
     * Человеческое имя callback.
     *
     * @param mixed $cb
     * @return string
     */
    protected function callbackName($cb)
    {
        if (is_string($cb)) {
            return $cb;
        }
        if (is_array($cb)) {
            $object = isset($cb[0]) ? $cb[0] : '';
            $method = isset($cb[1]) ? $cb[1] : '';
            if (is_object($object)) {
                return get_class($object) . '::' . $method;
            }
            return (string) $object . '::' . $method;
        }
        if (is_object($cb)) {
            return get_class($cb) . '::__invoke';
        }

        return gettype($cb);
    }

    /*
     * Поля настроек.
     */

    public function fieldUrl()
    {
        $value = isset($this->options['url']) ? $this->options['url'] : '';
        echo '<input type="url" class="regular-text" name="mailerservice_options[url]" value="' . esc_attr($value) . '">';
        echo '<p class="description">Адрес сервиса рассылки, например <code>http://mail.internal</code> (без /api/v1).</p>';
    }

    public function fieldApikey()
    {
        $value = isset($this->options['apikey']) ? $this->options['apikey'] : '';
        echo '<input type="text" class="regular-text" name="mailerservice_options[apikey]" value="' . esc_attr($value) . '" autocomplete="off">';
        echo '<p class="description">API-ключ проекта. Выдаётся на стороне сервиса: <code>php bin/mailer key:create wordpress</code></p>';
    }

    public function fieldMode()
    {
        $value = isset($this->options['mode']) ? $this->options['mode'] : 'queue';
        echo '<select name="mailerservice_options[mode]">';
        echo '<option value="queue"' . selected($value, 'queue', false) . '>очередь — вернуть управление сразу, письмо отправит воркер</option>';
        echo '<option value="sync"' . selected($value, 'sync', false) . '>сразу — дождаться результата отправки</option>';
        echo '</select>';
        echo '<p class="description">По умолчанию письмо ставится в очередь и страница не ждёт SMTP.</p>';
    }

    public function fieldTransport()
    {
        $value = isset($this->options['transport']) ? $this->options['transport'] : '';
        echo '<input type="text" class="regular-text" name="mailerservice_options[transport]" value="' . esc_attr($value) . '">';
        echo '<p class="description">Имя транспорта в сервисе. Доступны транспорты владельца проекта и общие. Пусто — транспорт по умолчанию.</p>';
    }

    public function fieldTag()
    {
        $value = isset($this->options['tag']) ? $this->options['tag'] : 'wordpress';
        echo '<input type="text" class="regular-text" name="mailerservice_options[tag]" value="' . esc_attr($value) . '">';
        echo '<p class="description">Метка писем в панели сервиса, по умолчанию <code>wordpress</code>.</p>';
    }

    public function fieldTimeout()
    {
        $value = isset($this->options['timeout']) ? (int) $this->options['timeout'] : 10;
        echo '<input type="number" min="1" max="120" name="mailerservice_options[timeout]" value="' . esc_attr($value) . '">';
        echo '<p class="description">Таймаут запроса к сервису, секунд.</p>';
    }

    public function fieldFromEmail()
    {
        $value = isset($this->options['from_email']) ? $this->options['from_email'] : '';
        echo '<input type="email" class="regular-text" name="mailerservice_options[from_email]" value="' . esc_attr($value) . '" placeholder="noreply@пример.ru">';
        echo '<p class="description">Отправитель всех писем. Должен быть разрешён транспорту сервиса: у Яндекса письмо упадёт с '
            . '«550 Sender address rejected», если From не принадлежит аккаунту. Пусто — заголовок From письма, '
            . 'а если его нет, отправителя возьмёт транспорт сервиса.</p>';
    }

    public function fieldFromName()
    {
        $value = isset($this->options['from_name']) ? $this->options['from_name'] : '';
        echo '<input type="text" class="regular-text" name="mailerservice_options[from_name]" value="' . esc_attr($value) . '" placeholder="Название сайта">';
        echo '<p class="description">Имя отправителя (необязательно).</p>';
    }

    public function fieldFallback()
    {
        $value = !empty($this->options['fallback']);
        echo '<label><input type="checkbox" name="mailerservice_options[fallback]" value="1"' . checked($value, true, false) . '> ';
        echo 'Если сервис недоступен — отправлять письмо штатными средствами WordPress.</label>';
    }

    public function fieldLogging()
    {
        $value = !empty($this->options['logging']);
        echo '<label><input type="checkbox" name="mailerservice_options[logging]" value="1"' . checked($value, true, false) . '> ';
        echo 'Записывать события отправки в журнал диагностики (раздел ниже).</label>';
    }
}