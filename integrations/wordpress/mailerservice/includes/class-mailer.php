<?php

/**
 * Перехват wp_mail: письмо уходит в очередь сервиса рассылки вместо mail().
 *
 * Плагин подписан на фильтр pre_wp_mail (WordPress 5.7+): он срабатывает
 * после фильтра wp_mail и до сборки письма в PHPMailer. Если вернуть
 * не-null, wp_mail вернёт это значение и штатная отправка не запустится.
 *
 * Запрос собирается из тех же данных, что получила бы wp_mail: получатели,
 * тема, тело, заголовки, вложения. Дальше письмо собирает сам сервис —
 * он добавляет MIME-кодирование, DKIM, очередь и повторы.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MailerService_Mailer
{
    /** @var int максимальное число записей в журнале диагностики */
    const LOG_LIMIT = 50;

    /** @var MailerService_API */
    protected $api;

    /** @var array настройки плагина */
    protected $options;

    public function __construct(MailerService_API $api, array $options)
    {
        $this->api     = $api;
        $this->options = $options;
    }

    /**
     * Подписка на перехват почты.
     */
    public function register()
    {
        add_filter('pre_wp_mail', array($this, 'intercept'), 10, 2);
    }

    /**
     * Обработчик pre_wp_mail.
     *
     * @param mixed $pre_wp_mail текущее значение фильтра
     * @param array $atts аргументы wp_mail: to, subject, message, headers, attachments
     * @return mixed true — отправлено, false — ошибка, null — не вмешиваться
     */
    public function intercept($pre_wp_mail, $atts)
    {
        // Другой плагин уже короткозамкнул отправку — не спорим.
        if ($pre_wp_mail !== null) {
            $this->writeLog('Другой плагин уже перехватил wp_mail (pre_wp_mail = ' . var_export($pre_wp_mail, true) . ')');
            return $pre_wp_mail;
        }

        if (!$this->isConfigured()) {
            $this->writeLog('Плагин не настроен: пустой адрес сервиса или API-ключ');
            return $pre_wp_mail;
        }

        $payload = $this->buildPayload($atts);
        if ($payload === null) {
            $this->writeLog('Письмо не собрано: не указан получатель');
            return null;
        }

        $subject = isset($payload['subject']) ? (string) $payload['subject'] : '';
        $to      = isset($atts['to']) ? $atts['to'] : '';

        if ($this->api->send($payload)) {
            $response = $this->api->getResponse();
            $this->writeLog('Письмо передано сервису', array(
                'to'      => $to,
                'subject' => $subject,
                'id'      => isset($response['id']) ? (string) $response['id'] : '',
                'status'  => isset($response['status']) ? (string) $response['status'] : '',
            ));
            return true;
        }

        $this->writeLog('Сервис не принял письмо', array(
            'to'      => $to,
            'subject' => $subject,
            'error'   => $this->api->getError(),
        ));

        // Запасной вариант: письмо важнее статистики, отдаём его обычной отправке.
        if (!empty($this->options['fallback'])) {
            $this->writeLog('Сервис недоступен — включаю запасную отправку (fallback)');
            return null;
        }

        $this->writeLog('Сервис недоступен и fallback выключен — письмо не отправлено');
        return false;
    }

    /**
     * Письмо из аргументов wp_mail в запрос к API.
     *
     * @param array $atts
     * @return array|null null, если письмо некуда отправлять
     */
    protected function buildPayload($atts)
    {
        $to          = isset($atts['to']) ? $atts['to'] : '';
        $subject     = isset($atts['subject']) ? (string) $atts['subject'] : '';
        $message     = isset($atts['message']) ? (string) $atts['message'] : '';
        $headers     = isset($atts['headers']) ? $atts['headers'] : array();
        $attachments = isset($atts['attachments']) ? $atts['attachments'] : array();

        if (empty($to)) {
            return null;
        }

        $parsed = $this->parseHeaders($headers);

        // Отправитель: настройка плагина, затем заголовок From письма.
        // Если ни того, ни другого нет — поле from не передаём вовсе, и сервис
        // возьмёт отправителя из транспорта или проекта. Так письма не падают
        // с «550 Sender address rejected», когда транспорт (Яндекс) шлёт только
        // со своих адресов, а WordPress по умолчанию ставит wordpress@домен.
        $fromEmail = isset($this->options['from_email']) ? trim((string) $this->options['from_email']) : '';
        $fromName  = isset($this->options['from_name']) ? trim((string) $this->options['from_name']) : '';

        if ($fromEmail === '') {
            $fromEmail = $parsed['from_email'];
            $fromName  = $parsed['from_name'];
        }

        // Тип содержимого: из заголовка Content-Type или text/plain.
        $contentType = $parsed['content_type'] !== '' ? $parsed['content_type'] : 'text/plain';
        $contentType = apply_filters('wp_mail_content_type', $contentType);
        if (strpos($contentType, ';') !== false) {
            list($contentType) = explode(';', $contentType, 2);
            $contentType = trim($contentType);
        }

        // Кодировка: сервис работает в UTF-8, остальное переводим, если можем.
        $charset = $parsed['charset'] !== '' ? $parsed['charset'] : get_bloginfo('charset');
        $charset = apply_filters('wp_mail_charset', $charset);
        if ($this->needsUtf8($charset)) {
            $subject  = $this->toUtf8($subject, $charset);
            $message  = $this->toUtf8($message, $charset);
            $fromName = $this->toUtf8($fromName, $charset);
        }

        $payload = array(
            'to'      => $to,
            'subject' => $subject,
            'meta'    => $this->meta(),
        );

        if ($fromEmail !== '') {
            $fromEmail = apply_filters('wp_mail_from', $fromEmail);
            $fromName  = apply_filters('wp_mail_from_name', $fromName);
            $payload['from'] = $fromName !== '' ? array('email' => $fromEmail, 'name' => $fromName) : $fromEmail;
        }

        if ($contentType === 'text/html') {
            $payload['html'] = $message;
        } else {
            $payload['text'] = $message;
        }

        if (!empty($parsed['cc'])) {
            $payload['cc'] = $parsed['cc'];
        }
        if (!empty($parsed['bcc'])) {
            $payload['bcc'] = $parsed['bcc'];
        }
        if (!empty($parsed['reply_to'])) {
            $payload['reply_to'] = implode(', ', $parsed['reply_to']);
        }
        if (!empty($parsed['custom'])) {
            foreach ($parsed['custom'] as $name => $value) {
                if (in_array($name, array('MIME-Version', 'X-Mailer', 'Content-Type'), true)) {
                    continue;
                }
                $payload['headers'][$name] = $value;
            }
        }

        $attachments = $this->buildAttachments($attachments);
        if (!empty($attachments)) {
            $payload['attachments'] = $attachments;
        }

        $tag = isset($this->options['tag']) ? trim((string) $this->options['tag']) : '';
        if ($tag !== '') {
            $payload['tag'] = $tag;
        }

        $transport = isset($this->options['transport']) ? trim((string) $this->options['transport']) : '';
        if ($transport !== '') {
            $payload['transport'] = $transport;
        }

        if (isset($this->options['mode']) && $this->options['mode'] === 'sync') {
            $payload['sync'] = true;
        }

        return $payload;
    }

    /**
     * Разбор заголовков wp_mail так же, как это делает сама wp_mail.
     *
     * @param string|array $headers
     * @return array
     */
    protected function parseHeaders($headers)
    {
        $parsed = array(
            'from_name'    => '',
            'from_email'   => '',
            'content_type' => '',
            'charset'      => '',
            'cc'           => array(),
            'bcc'          => array(),
            'reply_to'     => array(),
            'custom'       => array(),
        );

        if (empty($headers)) {
            return $parsed;
        }

        if (!is_array($headers)) {
            $tempheaders = explode("\n", str_replace("\r\n", "\n", $headers));
        } else {
            $tempheaders = $headers;
        }

        foreach ((array) $tempheaders as $header) {
            if (!is_string($header) || strpos($header, ':') === false) {
                continue;
            }

            list($name, $content) = explode(':', trim($header), 2);
            $name    = trim($name);
            $content = trim($content);

            switch (strtolower($name)) {
                case 'from':
                    $bracketPos = strpos($content, '<');
                    if ($bracketPos !== false) {
                        if ($bracketPos > 0) {
                            $parsed['from_name'] = str_replace('"', '', trim(substr($content, 0, $bracketPos - 1)));
                        }
                        $parsed['from_email'] = str_replace('>', '', trim(substr($content, $bracketPos + 1)));
                    } elseif ($content !== '') {
                        $parsed['from_email'] = $content;
                    }
                    break;

                case 'content-type':
                    if (strpos($content, ';') !== false) {
                        list($type, $rest) = explode(';', $content, 2);
                        $parsed['content_type'] = trim($type);
                        if (preg_match('/charset=["\']?([^"\';]+)/i', $rest, $m)) {
                            $parsed['charset'] = trim($m[1]);
                        }
                    } elseif ($content !== '') {
                        $parsed['content_type'] = $content;
                    }
                    break;

                case 'cc':
                    $parsed['cc'] = array_merge($parsed['cc'], explode(',', $content));
                    break;

                case 'bcc':
                    $parsed['bcc'] = array_merge($parsed['bcc'], explode(',', $content));
                    break;

                case 'reply-to':
                    $parsed['reply_to'] = array_merge($parsed['reply_to'], explode(',', $content));
                    break;

                default:
                    $parsed['custom'][$name] = $content;
                    break;
            }
        }

        return $parsed;
    }

    /**
     * Отправитель по умолчанию, как у wp_mail: wordpress@домен без www.
     *
     * @return string
     */
    protected function defaultFromEmail()
    {
        $sitename = wp_parse_url(network_home_url(), PHP_URL_HOST);
        if (strpos($sitename, 'www.') === 0) {
            $sitename = substr($sitename, 4);
        }

        return 'wordpress@' . $sitename;
    }

    /**
     * Вложения wp_mail — пути к файлам. Читаем файл и передаём base64,
     * чтобы сервису не требовался доступ к диску сайта.
     *
     * @param string|array $attachments
     * @return array
     */
    protected function buildAttachments($attachments)
    {
        if (empty($attachments)) {
            return array();
        }

        if (!is_array($attachments)) {
            $attachments = explode("\n", str_replace("\r\n", "\n", $attachments));
        }

        $list = array();
        foreach ((array) $attachments as $path) {
            if (!is_string($path) || !is_readable($path)) {
                continue;
            }

            $data = file_get_contents($path);
            if ($data === false) {
                continue;
            }

            $attachment = array(
                'name'    => basename($path),
                'content' => base64_encode($data),
            );

            if (function_exists('wp_check_filetype')) {
                $filetype = wp_check_filetype($attachment['name']);
                if (!empty($filetype['type'])) {
                    $attachment['content_type'] = $filetype['type'];
                }
            }

            $list[] = $attachment;
        }

        return $list;
    }

    /**
     * Сведения о письме для панели сервиса и вебхуков.
     *
     * @return array
     */
    protected function meta()
    {
        $meta = array(
            'source' => 'wordpress',
            'site'   => (string) get_bloginfo('name'),
            'url'    => (string) home_url(),
        );

        $user = wp_get_current_user();
        if ($user instanceof WP_User && $user->exists() && $user->user_login !== '') {
            $meta['user'] = $user->user_login;
        }

        return $meta;
    }

    /**
     * Настроен ли плагин: без адреса и ключа отправлять некуда.
     *
     * @return bool
     */
    protected function isConfigured()
    {
        $url = isset($this->options['url']) ? trim((string) $this->options['url']) : '';
        $key = isset($this->options['apikey']) ? trim((string) $this->options['apikey']) : '';

        return $url !== '' && $key !== '';
    }

    /**
     * Нужно ли переводить письмо из этой кодировки в UTF-8.
     *
     * @param string $charset
     * @return bool
     */
    protected function needsUtf8($charset)
    {
        $charset = strtolower(trim((string) $charset));

        return $charset !== ''
            && $charset !== 'utf-8'
            && $charset !== 'utf8'
            && $charset !== 'us-ascii'
            && function_exists('mb_convert_encoding');
    }

    /**
     * Перевод строки в UTF-8; при ошибке возвращает исходное значение.
     *
     * @param string $value
     * @param string $charset
     * @return string
     */
    protected function toUtf8($value, $charset)
    {
        $converted = @mb_convert_encoding($value, 'UTF-8', $charset);

        return is_string($converted) && $converted !== '' ? $converted : $value;
    }

    /**
     * Запись в журнал диагностики и в журнал ошибок PHP.
     *
     * Журнал хранится в опции mailerservice_log кольцевым буфером —
     * чтобы владелец сайта мог посмотреть последние события на странице
     * настроек, не имея доступа к логу сервера.
     *
     * @param string $message
     * @param array $context
     */
    protected function writeLog($message, array $context = array())
    {
        if (!$this->isLogEnabled()) {
            return;
        }

        $entry = array(
            'time'    => current_time('mysql'),
            'message' => $message,
            'context' => $context,
        );

        $log   = $this->getLog();
        $log[] = $entry;
        if (count($log) > self::LOG_LIMIT) {
            $log = array_slice($log, -self::LOG_LIMIT);
        }

        update_option('mailerservice_log', $log, false);

        $suffix = $context !== array() ? ' ' . (string) wp_json_encode($context, JSON_UNESCAPED_UNICODE) : '';
        error_log('[mailerservice] ' . $message . $suffix);
    }

    /**
     * Журнал диагностики.
     *
     * @return array
     */
    public function getLog()
    {
        $log = get_option('mailerservice_log', array());

        return is_array($log) ? $log : array();
    }

    /**
     * Очистка журнала диагностики.
     */
    public function clearLog()
    {
        delete_option('mailerservice_log');
    }

    /**
     * Включён ли журнал диагностики.
     *
     * @return bool
     */
    protected function isLogEnabled()
    {
        return !isset($this->options['logging']) || !empty($this->options['logging']);
    }
}