<?php

/**
 * Перехватывает отправку почты DokuWiki и отдаёт письмо сервису рассылки.
 *
 * Само письмо собирает DokuWiki — плагин берёт готовый MIME (Mailer::dump())
 * и кладёт его в очередь сервиса. Поэтому шаблоны, вложения и подписи остаются
 * ровно такими, какими их сделала вики.
 */

if (!defined('DOKU_INC')) {
    die();
}

class action_plugin_mailerservice extends DokuWiki_Action_Plugin
{
    /**
     * @param Doku_Event_Handler $controller
     * @return void
     */
    public function register(Doku_Event_Handler $controller)
    {
        $controller->register_hook('MAIL_MESSAGE_SEND', 'BEFORE', $this, 'handleSend');
    }

    /**
     * @param Doku_Event $event
     * @param mixed $param
     * @return void
     */
    public function handleSend(Doku_Event $event, $param)
    {
        /** @var helper_plugin_mailerservice $client */
        $client = plugin_load('helper', 'mailerservice');
        if ($client === null || !$client->isConfigured()) {
            // Плагин не настроен — пусть DokuWiki отправляет как раньше
            return;
        }

        $mail = isset($event->data['mail']) ? $event->data['mail'] : null;
        if (!is_object($mail) || !method_exists($mail, 'dump')) {
            return;
        }

        $raw = $mail->dump();
        if (!is_string($raw) || trim($raw) === '') {
            $this->fail($event, $this->getLang('err_empty'));
            return;
        }

        $ok = $client->sendRaw($raw, ['meta' => $this->meta()]);

        if ($ok) {
            $event->data['success'] = true;
            $event->preventDefault();
            $event->stopPropagation();
            return;
        }

        $this->fail($event, $client->getError());
    }

    /**
     * Сведения о письме, которые вернутся в вебхуке и видны в панели.
     *
     * @return array<string, string>
     */
    protected function meta()
    {
        global $conf;
        global $INPUT;

        $meta = ['source' => 'dokuwiki', 'wiki' => (string) $conf['title']];

        if (isset($INPUT) && $INPUT->server->str('REMOTE_USER') !== '') {
            $meta['user'] = $INPUT->server->str('REMOTE_USER');
        }

        return $meta;
    }

    /**
     * Сервис не принял письмо: либо отдаём его обычной отправке, либо считаем неудачей.
     *
     * @param Doku_Event $event
     * @param string $error
     * @return void
     */
    protected function fail(Doku_Event $event, $error)
    {
        $this->log('Не удалось передать письмо сервису рассылки: ' . $error);

        if ($this->getConf('fallback')) {
            // Отправляем штатным способом — письмо важнее статистики
            return;
        }

        $event->data['success'] = false;
        $event->preventDefault();
        $event->stopPropagation();
    }

    /**
     * @param string $message
     * @return void
     */
    protected function log($message)
    {
        if (class_exists('\dokuwiki\Logger')) {
            \dokuwiki\Logger::error($message, [], __FILE__, __LINE__);
            return;
        }

        dbglog($message);
    }
}
