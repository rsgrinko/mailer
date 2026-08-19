<?php

/**
 * Удаление настроек плагина при деинсталляции.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('mailerservice_options');