<?php

/**
 * Страница администратора: настройки одним взглядом, состояние сервиса и тестовое письмо.
 */

if (!defined('DOKU_INC')) {
    die();
}

class admin_plugin_mailerservice extends DokuWiki_Admin_Plugin
{
    /** @var array<string, mixed>|null ответ /api/v1/health */
    protected $health = null;

    /** @var string ошибка проверки */
    protected $healthError = '';

    /** @var string результат отправки тестового письма */
    protected $testResult = '';

    /** @var bool успешна ли отправка тестового письма */
    protected $testOk = false;

    /** @var string адрес из формы */
    protected $testTo = '';

    /**
     * @return int
     */
    public function getMenuSort()
    {
        return 300;
    }

    /**
     * @param string $language
     * @return string
     */
    public function getMenuText($language)
    {
        return $this->getLang('menu');
    }

    /**
     * @return bool
     */
    public function forAdminOnly()
    {
        return true;
    }

    /**
     * @return void
     */
    public function handle()
    {
        global $INPUT;

        $action = $INPUT->str('mailerservice');
        if ($action === '' || !checkSecurityToken()) {
            return;
        }

        /** @var helper_plugin_mailerservice $client */
        $client = plugin_load('helper', 'mailerservice');
        if ($client === null) {
            return;
        }

        if ($action === 'check') {
            $this->health = $client->health();
            if ($this->health === null) {
                $this->healthError = $client->getError();
            }

            return;
        }

        if ($action === 'test') {
            $this->testTo = trim($INPUT->str('to'));

            if (!mail_isvalid($this->testTo)) {
                $this->testResult = $this->getLang('test_bademail');
                return;
            }

            $mail = new Mailer();
            $mail->to($this->testTo);
            $mail->subject($this->getLang('test_subject'));
            $mail->setBody($this->getLang('test_body'));

            $this->testOk     = (bool) $mail->send();
            $this->testResult = $this->testOk ? $this->getLang('test_ok') : $this->getLang('test_fail');
        }
    }

    /**
     * @return void
     */
    public function html()
    {
        global $ID;

        /** @var helper_plugin_mailerservice $client */
        $client = plugin_load('helper', 'mailerservice');

        echo '<h1>' . hsc($this->getLang('menu')) . '</h1>';

        if ($client === null || !$client->isConfigured()) {
            echo '<p class="error">' . hsc($this->getLang('notconfigured')) . '</p>';
        }

        $this->htmlSettings();
        $this->htmlHealth($ID);
        $this->htmlTest($ID);
    }

    /**
     * @return void
     */
    protected function htmlSettings()
    {
        $rows = [
            $this->getLang('s_url')       => $this->getConf('url') !== '' ? $this->getConf('url') : '-',
            $this->getLang('s_mode')      => $this->getConf('mode'),
            $this->getLang('s_transport') => $this->getConf('transport') !== '' ? $this->getConf('transport') : '-',
            $this->getLang('s_tag')       => $this->getConf('tag') !== '' ? $this->getConf('tag') : '-',
            $this->getLang('s_fallback')  => $this->getConf('fallback') ? $this->getLang('yes') : $this->getLang('no'),
        ];

        echo '<h2>' . hsc($this->getLang('settings')) . '</h2>';
        echo '<table class="inline">';
        foreach ($rows as $name => $value) {
            echo '<tr><td>' . hsc($name) . '</td><td>' . hsc((string) $value) . '</td></tr>';
        }
        echo '</table>';
    }

    /**
     * @param string $id
     * @return void
     */
    protected function htmlHealth($id)
    {
        echo '<h2>' . hsc($this->getLang('health')) . '</h2>';

        echo '<form action="' . wl($id) . '" method="post">';
        echo '<input type="hidden" name="do" value="admin">';
        echo '<input type="hidden" name="page" value="mailerservice">';
        echo '<input type="hidden" name="mailerservice" value="check">';
        echo '<input type="hidden" name="sectok" value="' . formSecurityToken(false) . '">';
        echo '<button type="submit">' . hsc($this->getLang('h_check')) . '</button>';
        echo '</form>';

        if ($this->healthError !== '') {
            echo '<p class="error">' . hsc($this->getLang('h_fail') . ': ' . $this->healthError) . '</p>';
            return;
        }

        if ($this->health === null) {
            return;
        }

        $checks = isset($this->health['checks']) && is_array($this->health['checks']) ? $this->health['checks'] : [];
        $queue  = isset($checks['queue']) && is_array($checks['queue']) ? $checks['queue'] : [];
        $worker = isset($checks['worker']) && is_array($checks['worker']) ? $checks['worker'] : [];

        $queueText = sprintf(
            '%s: %d, %s: %d, %s: %d',
            $this->getLang('h_ready'),
            (int) ($queue['ready'] ?? 0),
            $this->getLang('h_delayed'),
            (int) ($queue['delayed'] ?? 0),
            $this->getLang('h_failed'),
            (int) ($queue['failed'] ?? 0)
        );

        $workerText = empty($worker['last_seen'])
            ? $this->getLang('h_never')
            : $this->getLang('h_lastseen') . ': ' . $worker['last_seen'];

        $rows = [
            $this->getLang('h_status')   => (string) ($this->health['status'] ?? '?'),
            $this->getLang('h_database') => $this->flag(!empty($checks['database']['ok'])),
            $this->getLang('h_queue')    => $queueText,
            $this->getLang('h_worker')   => $this->flag(!empty($worker['ok'])) . ', ' . $workerText,
        ];

        echo '<table class="inline">';
        foreach ($rows as $name => $value) {
            echo '<tr><td>' . hsc($name) . '</td><td>' . hsc($value) . '</td></tr>';
        }
        echo '</table>';
    }

    /**
     * @param string $id
     * @return void
     */
    protected function htmlTest($id)
    {
        echo '<h2>' . hsc($this->getLang('test')) . '</h2>';
        echo '<p>' . hsc($this->getLang('test_hint')) . '</p>';

        if ($this->testResult !== '') {
            echo '<p class="' . ($this->testOk ? 'success' : 'error') . '">' . hsc($this->testResult) . '</p>';
        }

        echo '<form action="' . wl($id) . '" method="post">';
        echo '<input type="hidden" name="do" value="admin">';
        echo '<input type="hidden" name="page" value="mailerservice">';
        echo '<input type="hidden" name="mailerservice" value="test">';
        echo '<input type="hidden" name="sectok" value="' . formSecurityToken(false) . '">';
        echo '<label>' . hsc($this->getLang('test_to')) . ' </label>';
        echo '<input type="email" name="to" value="' . hsc($this->testTo) . '" required>';
        echo ' <button type="submit">' . hsc($this->getLang('test_send')) . '</button>';
        echo '</form>';
    }

    /**
     * @param bool $ok
     * @return string
     */
    protected function flag($ok)
    {
        return $ok ? $this->getLang('h_ok') : $this->getLang('h_bad');
    }
}
