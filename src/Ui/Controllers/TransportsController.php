<?php

declare(strict_types=1);

namespace Mailer\Ui\Controllers;

use Mailer\Domain\Permission;
use Mailer\Domain\Scope;
use Mailer\Domain\Viewer;
use Mailer\Http\Request;
use Mailer\Http\Response;
use Mailer\RateLimit\RateLimiter;
use Mailer\Repository\TransportRepository;
use Mailer\Repository\UserRepository;
use Mailer\Support\Config;
use Mailer\Transport\TransportFactory;
use Mailer\Ui\Audit;
use Mailer\Ui\View;
use Throwable;

/**
 * Транспорты в панели: список, создание, правка, проверка связи.
 */
final class TransportsController extends ResourceController
{
    private TransportRepository $transports;
    private RateLimiter $limiter;
    private UserRepository $users;

    public function __construct(
        TransportRepository $transports,
        RateLimiter $limiter,
        UserRepository $users
    ) {
        $this->transports = $transports;
        $this->limiter    = $limiter;
        $this->users      = $users;
    }

    public function index(Request $request, Scope $scope): Response
    {
        $result = $this->transports->paginate(
            (int) $request->query('page', 1),
            (int) Config::get('ui.per_page', 30),
            $scope
        );

        $usage = [];
        foreach ($result['items'] as $item) {
            $usage[(int) $item['id']] = $this->limiter->transportUsage((int) $item['id']);
        }

        return Response::html(View::render('transports', [
            'active' => 'transports',
            'items'  => $result['items'],
            'result' => $result,
            'usage'  => $usage,
        ], 'Транспорты'));
    }

    /**
     * Форма создания или правки.
     */
    public function form(Request $request, ?int $id, Scope $scope, Viewer $viewer): Response
    {
        $transport = $this->requireIfEditing($id, $id === null ? null : $this->transports->find($id, $scope));

        return Response::html(View::render('transport_form', [
            'active'    => 'transports',
            'transport' => $transport,
            'all'       => $this->transports->all(false, $scope),
            'owners'    => $viewer->isAdmin() ? $this->users->all() : [],
            // Общий транспорт и основной по умолчанию — дело администратора
            'canShare'  => $viewer->isAdmin(),
            // Чужой или общий транспорт показываем только на просмотр
            'readOnly'  => $transport !== null && !$this->editable($transport, $viewer),
        ], $transport === null ? 'Новый транспорт' : 'Транспорт «' . $transport['name'] . '»'));
    }

    /**
     * Правится ли транспорт этим пользователем. Общий транспорт видят все,
     * но настройки и пароли в нём меняет только тот, кому доступны чужие данные.
     *
     * @param array<string, mixed> $transport
     */
    private function editable(array $transport, Viewer $viewer): bool
    {
        return $viewer->isAdmin() || (int) ($transport['owner_id'] ?? 0) === $viewer->id();
    }

    /**
     * Сохранение формы.
     */
    public function save(Request $request, Scope $scope, Viewer $viewer): Response
    {
        $id   = (int) $request->input('id', 0);
        $type = (string) $request->input('type', 'smtp');

        // Чужой транспорт для правки недоступен, общий — только администратору
        $current = $id > 0 ? $this->require($this->transports->find($id, $scope)) : null;
        if ($current !== null && !$this->editable($current, $viewer)) {
            View::flash('Этот транспорт заведён администратором — менять его нельзя', 'error');

            return Response::redirect(View::route('ui.transports.show', ['id' => $id]));
        }

        $settings = match ($type) {
            'smtp' => [
                'host'        => trim((string) $request->input('host', '')),
                'port'        => (int) $request->input('port', 465),
                'encryption'  => (string) $request->input('encryption', 'ssl'),
                'username'    => trim((string) $request->input('username', '')),
                'password'    => (string) $request->input('password', ''),
                'auth_mode'   => (string) $request->input('auth_mode', 'auto'),
                'timeout'     => (int) $request->input('timeout', 30),
                'verify_peer' => $request->input('verify_peer') !== null,
            ],
            'sendmail' => ['path' => (string) $request->input('path', '/usr/sbin/sendmail')],
            'log'      => ['dir' => (string) $request->input('dir', MAILER_ROOT . '/var/spool/sent')],
            'failover', 'roundrobin' => [
                'transports' => array_values(array_filter(array_map(
                    'trim',
                    explode(',', (string) $request->input('transports', ''))
                ))),
            ],
            default => [],
        };

        // Подмена отправителя — общая настройка, как и DKIM
        if ($request->input('force_from') !== null) {
            $settings['force_from'] = true;
        }

        // DKIM — общая настройка для любого транспорта
        if ($request->input('dkim_enabled') !== null) {
            $settings['dkim'] = [
                'enabled'     => true,
                'domain'      => trim((string) $request->input('dkim_domain', '')),
                'selector'    => trim((string) $request->input('dkim_selector', 'mail')),
                'private_key' => (string) $request->input('dkim_key', ''),
            ];

            // Пустой ключ в форме означает «оставить прежний»
            if ($settings['dkim']['private_key'] === '' && $current !== null) {
                $settings['dkim']['private_key'] = (string) ($current['settings']['dkim']['private_key'] ?? '');
            }
        }

        $data = [
            'name'        => trim((string) $request->input('name', '')),
            'type'        => $type,
            'settings'    => $settings,
            'from_email'  => trim((string) $request->input('from_email', '')),
            'from_name'   => trim((string) $request->input('from_name', '')),
            'priority'    => (int) $request->input('priority', 100),
            'daily_limit' => (int) $request->input('daily_limit', 0),
            'active'      => $request->input('active') !== null,
        ];

        // Основной и общий транспорт — общесервисные настройки, их ставит администратор
        if ($viewer->isAdmin()) {
            $data['is_default'] = $request->input('is_default') !== null;
            $data['shared']     = $request->input('shared') !== null;

            $owner = (int) $request->input('owner_id', 0);
            if ($owner > 0 || $id === 0) {
                $data['owner_id'] = $owner;
            }
        } elseif ($id === 0) {
            $data['owner_id'] = $viewer->id();
        }

        try {
            if ($id > 0) {
                $this->transports->update($id, $data);
                Audit::updated('transport', $id, 'транспорт «' . $data['name'] . '»');
                View::flash('Транспорт сохранён');
            } else {
                $id = $this->transports->create($data);
                Audit::created('transport', $id, 'транспорт «' . $data['name'] . '» (' . $data['type'] . ')');
                View::flash('Транспорт создан');
            }
        } catch (Throwable $e) {
            View::flash('Не сохранилось: ' . $e->getMessage(), 'error');

            return Response::redirect($id > 0 ? View::route('ui.transports.show', ['id' => $id]) : View::route('ui.transports.new'));
        }

        return Response::redirect(View::route('ui.transports.show', ['id' => $id]));
    }

    /**
     * Кнопки в списке и в форме.
     */
    public function action(Request $request, int $id, string $action, Scope $scope, Viewer $viewer): Response
    {
        $transport = $this->require($this->transports->find($id, $scope));

        // На маршруте одно право на все кнопки, поэтому сверяем каждую отдельно
        $required = $action === 'test' ? Permission::TRANSPORTS_TEST : Permission::TRANSPORTS_MANAGE;

        if (!$viewer->can($required)) {
            View::flash('Нет прав на это действие', 'error');

            return Response::redirect(View::route('ui.transports'));
        }

        // Общий транспорт можно проверить, но не выключить и не удалить
        if ($action !== 'test' && !$this->editable($transport, $viewer)) {
            View::flash('Этот транспорт заведён администратором — менять его нельзя', 'error');

            return Response::redirect(View::route('ui.transports'));
        }

        switch ($action) {
            case 'test':
                try {
                    $result = (new TransportFactory($this->transports))->fromRow($transport)->test();
                    $this->transports->markUsed($id, null);
                    View::flash('Проверка прошла: ' . $result);
                } catch (Throwable $e) {
                    $this->transports->markUsed($id, $e->getMessage());
                    View::flash('Проверка не прошла: ' . $e->getMessage(), 'error');
                }
                break;

            case 'default':
                $this->transports->setDefault($id);
                Audit::action('transport', $id, 'транспорт «' . $transport['name'] . '» сделан основным');
                View::flash('Транспорт «' . $transport['name'] . '» теперь основной');
                break;

            case 'toggle':
                $this->transports->update($id, ['active' => (int) $transport['active'] !== 1]);
                Audit::updated('transport', $id, ((int) $transport['active'] === 1 ? 'выключен' : 'включён') . ' транспорт «' . $transport['name'] . '»');
                View::flash((int) $transport['active'] === 1 ? 'Транспорт выключен' : 'Транспорт включён');
                break;

            case 'delete':
                $this->transports->delete($id);
                Audit::deleted('transport', $id, 'транспорт «' . $transport['name'] . '»');
                View::flash('Транспорт удалён');

                return Response::redirect(View::route('ui.transports'));

            default:
                View::flash('Неизвестное действие: ' . $action, 'error');
        }

        return Response::redirect(View::route('ui.transports'));
    }
    protected function listRoute(): string
    {
        return 'ui.transports';
    }

    protected function notFoundMessage(): string
    {
        return 'Транспорт не найден';
    }
}
