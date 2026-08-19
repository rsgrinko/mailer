<?php

/**
 * Plugin Name: Сервис рассылки
 * Plugin URI: https://github.com/rsgrinko/mailer
 * Description: Отправка почты WordPress через сервис рассылки по HTTP API вместо mail(). Вся почта сайта уходит в очередь сервиса: регистрация, сброс пароля, уведомления плагинов, формы.
 * Version: 1.0.2
 * Requires PHP: 7.0
 * Requires at least: 5.7
 * Author: Роман Гринько
 * Author URI: https://github.com/rsgrinko
 * License: GPL-2.0-or-later
 * Text Domain: mailerservice
 */

/**
 * Плагин перехватывает wp_mail и отдаёт письма сервису рассылки по HTTP API.
 *
 * Пока не заполнены адрес сервиса и API-ключ (Параметры -> Сервис рассылки),
 * плагин не вмешивается: почта уходит штатными средствами WordPress.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('MAILERSERVICE_VERSION', '1.0.2');
define('MAILERSERVICE_FILE', __FILE__);
define('MAILERSERVICE_DIR', plugin_dir_path(__FILE__));

require_once MAILERSERVICE_DIR . 'includes/class-api.php';
require_once MAILERSERVICE_DIR . 'includes/class-mailer.php';
require_once MAILERSERVICE_DIR . 'includes/class-admin.php';

/**
 * Настройки по умолчанию.
 *
 * @return array
 */
function mailerservice_default_options()
{
    return array(
        'url'       => '',
        'apikey'    => '',
        'mode'      => 'queue',
        'transport' => '',
        'tag'       => 'wordpress',
        'from_email' => '',
        'from_name'  => '',
        'timeout'   => 10,
        'fallback'  => 1,
        'logging'   => 1,
    );
}

/**
 * Текущие настройки плагина.
 *
 * @return array
 */
function mailerservice_options()
{
    $options = get_option('mailerservice_options', array());
    $options = is_array($options) ? $options : array();

    return array_merge(mailerservice_default_options(), $options);
}

/**
 * Запуск плагина: перехватчик почты и страница настроек.
 */
function mailerservice_init()
{
    $options = mailerservice_options();

    $api = new MailerService_API(
        isset($options['url']) ? $options['url'] : '',
        isset($options['apikey']) ? $options['apikey'] : '',
        isset($options['timeout']) ? $options['timeout'] : 10
    );

    $mailer = new MailerService_Mailer($api, $options);
    $mailer->register();

    if (is_admin()) {
        $admin = new MailerService_Admin($api, $options);
        $admin->register();
    }
}

mailerservice_init();
