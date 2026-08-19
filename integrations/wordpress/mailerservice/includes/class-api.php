<?php

/**
 * Клиент HTTP API сервиса рассылки.
 *
 * Ничего не знает про wp_mail — только собирает запрос, отправляет его
 * и разбирает ответ. Используется и перехватчиком почты, и страницей настроек.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MailerService_API
{
    /** @var string адрес сервиса без завершающего слэша */
    protected $base = '';

    /** @var string API-ключ проекта */
    protected $apiKey = '';

    /** @var int таймаут запроса, секунд */
    protected $timeout = 10;

    /** @var string текст последней ошибки */
    protected $error = '';

    /** @var array тело последнего ответа */
    protected $response = array();

    public function __construct($baseUrl, $apiKey, $timeout = 10)
    {
        $this->base    = rtrim(trim((string) $baseUrl), '/');
        $this->apiKey  = trim((string) $apiKey);
        $this->timeout = max(1, (int) $timeout);
    }

    /**
     * Текст последней ошибки.
     *
     * @return string
     */
    public function getError()
    {
        return $this->error;
    }

    /**
     * Тело последнего ответа.
     *
     * @return array
     */
    public function getResponse()
    {
        return $this->response;
    }

    /**
     * Отдаёт сервису письмо. Возвращает true, если сервис принял его.
     *
     * @param array $payload тело запроса
     * @return bool
     */
    public function send(array $payload)
    {
        return $this->request('POST', '/api/v1/messages', $payload) !== null;
    }

    /**
     * Проверка ключа: запрос списка писем проекта. Неверный ключ или
     * отключённый проект вернут 401/403, связь с сервисом — сетевую ошибку.
     *
     * @return bool
     */
    public function ping()
    {
        return $this->request('GET', '/api/v1/messages?per_page=1', null) !== null;
    }

    /**
     * Состояние сервиса: база, очередь, воркер.
     *
     * @return array|null null, если сервис недоступен
     */
    public function health()
    {
        return $this->request('GET', '/api/v1/health', null);
    }

    /**
     * Запрос к API.
     *
     * @param string $method
     * @param string $path
     * @param array|null $payload
     * @return array|null тело ответа или null при ошибке
     */
    protected function request($method, $path, $payload = null)
    {
        $this->error    = '';
        $this->response = array();

        if ($this->base === '') {
            $this->error = 'Не указан адрес сервиса рассылки';
            return null;
        }

        if ($this->apiKey === '') {
            $this->error = 'Не указан API-ключ проекта';
            return null;
        }

        $body = '';
        if ($payload !== null) {
            $body = (string) wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($body === '') {
                $this->error = 'Не удалось собрать запрос к сервису';
                return null;
            }
        }

        $args = array(
            'method'      => $method,
            'timeout'     => $this->timeout,
            'redirection' => 0,
            'user-agent'  => 'WordPress/' . get_bloginfo('version') . ' (mailerservice)',
            'headers'     => array(
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Accept'        => 'application/json',
            ),
        );

        if ($payload !== null) {
            $args['headers']['Content-Type'] = 'application/json; charset=utf-8';
            $args['body']                    = $body;
        }

        $response = wp_remote_request($this->base . $path, $args);

        if (is_wp_error($response)) {
            $this->error = 'Сервис недоступен: ' . $response->get_error_message();
            return null;
        }

        $status  = (int) wp_remote_retrieve_response_code($response);
        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
        $decoded = is_array($decoded) ? $decoded : array();
        $this->response = $decoded;

        if ($status < 200 || $status >= 300) {
            $message = isset($decoded['error']['message'])
                ? (string) $decoded['error']['message']
                : 'Сервис ответил кодом ' . $status;

            $details = '';
            if (isset($decoded['error']['details']['errors']) && is_array($decoded['error']['details']['errors'])) {
                $details = implode('; ', $decoded['error']['details']['errors']);
            }

            $this->error = $details !== '' ? $message . ': ' . $details : $message;
            return null;
        }

        return $decoded;
    }
}