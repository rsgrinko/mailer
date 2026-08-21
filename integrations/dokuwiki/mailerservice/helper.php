<?php

/**
 * Клиент HTTP API сервиса рассылки.
 *
 * Ничего не знает про события DokuWiki — только собирает запрос, отправляет его
 * и разбирает ответ. Используется и обработчиком почты, и админ-страницей.
 */

if (!defined('DOKU_INC')) {
    die();
}

class helper_plugin_mailerservice extends DokuWiki_Plugin
{
    /** @var string текст последней ошибки */
    protected $error = '';

    /** @var array<string, mixed> тело последнего ответа */
    protected $response = [];

    /**
     * Настроен ли плагин: без адреса и ключа отправлять некуда.
     *
     * @return bool
     */
    public function isConfigured()
    {
        return $this->baseUrl() !== '' && trim((string) $this->getConf('apikey')) !== '';
    }

    /**
     * Адрес сервиса без завершающего слэша.
     *
     * @return string
     */
    public function baseUrl()
    {
        return rtrim(trim((string) $this->getConf('url')), '/');
    }

    /**
     * Ошибка последнего запроса.
     *
     * @return string
     */
    public function getError()
    {
        return $this->error;
    }

    /**
     * Ответ последнего запроса.
     *
     * @return array<string, mixed>
     */
    public function getResponse()
    {
        return $this->response;
    }

    /**
     * Отдаёт сервису готовое письмо целиком (то, что собрал класс Mailer).
     *
     * @param string $raw письмо со всеми заголовками
     * @param array<string, mixed> $extra дополнительные поля запроса: meta, tag, priority
     * @return bool
     */
    public function sendRaw($raw, array $extra = [])
    {
        $payload = ['raw' => $raw];

        $tag = trim((string) $this->getConf('tag'));
        if ($tag !== '') {
            $payload['tag'] = $tag;
        }

        $transport = trim((string) $this->getConf('transport'));
        if ($transport !== '') {
            $payload['transport'] = $transport;
        }

        if ($this->getConf('mode') === 'sync') {
            $payload['sync'] = true;
        }

        return $this->send(array_merge($payload, $extra));
    }

    /**
     * Отправляет письмо обычным (не raw) запросом — удобно для проверок.
     *
     * @param array<string, mixed> $payload
     * @return bool
     */
    public function send(array $payload)
    {
        $result = $this->request('POST', '/api/v1/messages', $payload);

        return $result !== null;
    }

    /**
     * Состояние сервиса: база, очередь, воркер.
     *
     * @return array<string, mixed>|null null, если сервис недоступен
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
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed>|null тело ответа или null при ошибке
     */
    public function request($method, $path, $payload = null)
    {
        $this->error    = '';
        $this->response = [];

        $base = $this->baseUrl();
        if ($base === '') {
            $this->error = $this->getLang('err_noturl');
            return null;
        }

        $body = '';
        if ($payload !== null) {
            $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($body === false) {
                $this->error = $this->getLang('err_json') . ': ' . json_last_error_msg();
                return null;
            }
        }

        $class = class_exists('\dokuwiki\HTTP\DokuHTTPClient')
            ? '\dokuwiki\HTTP\DokuHTTPClient'
            : '\DokuHTTPClient';

        /** @var DokuHTTPClient $http */
        $http                = new $class();
        $http->timeout       = max(1, (int) $this->getConf('timeout'));
        $http->keep_alive    = false;
        $http->max_bodysize  = 0;
        $http->headers['Accept']        = 'application/json';
        $http->headers['Content-Type']  = 'application/json; charset=utf-8';
        $http->headers['Authorization'] = 'Bearer ' . trim((string) $this->getConf('apikey'));

        $http->sendRequest($base . $path, $body, $method);

        $status   = (int) $http->status;
        $decoded  = json_decode((string) $http->resp_body, true);
        $decoded  = is_array($decoded) ? $decoded : [];
        $this->response = $decoded;

        if ($status === 0) {
            $this->error = $this->getLang('err_connect') . ': ' . ($http->error !== '' ? $http->error : $base);
            return null;
        }

        if ($status < 200 || $status >= 300) {
            // 502 в режиме sync — не ошибка запроса: письмо приняли, а почтовый
            // сервер его не взял. Блока error там нет, причина лежит в info
            if (isset($decoded['error']['message'])) {
                $message = (string) $decoded['error']['message'];
            } elseif (isset($decoded['info']) && $decoded['info'] !== '') {
                $message = (string) $decoded['info'];
            } else {
                $message = $this->getLang('err_status') . ' ' . $status;
            }

            $details = isset($decoded['error']['details']['errors']) && is_array($decoded['error']['details']['errors'])
                ? implode('; ', $decoded['error']['details']['errors'])
                : '';

            $this->error = $details !== '' ? $message . ': ' . $details : $message;
            return null;
        }

        return $decoded;
    }
}
