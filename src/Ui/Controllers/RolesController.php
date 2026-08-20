<?php

declare(strict_types=1);

namespace Mailer\Ui\Controllers;

use Mailer\Domain\Permission;
use Mailer\Http\Request;
use Mailer\Http\Response;
use Mailer\Repository\RoleRepository;
use Mailer\Support\Config;
use Mailer\Ui\Audit;
use Mailer\Ui\View;
use Throwable;

/**
 * Роли панели: набор прав под именем. Роль выдаётся пользователю, отдельных
 * галочек у человека нет — поменяли роль, поменялось у всех, кому она выдана.
 */
final class RolesController extends ResourceController
{
    private RoleRepository $roles;

    public function __construct(RoleRepository $roles)
    {
        $this->roles = $roles;
    }

    public function index(Request $request): Response
    {
        $result = $this->roles->paginate(
            (int) $request->query('page', 1),
            (int) Config::get('ui.per_page', 30)
        );

        $usage = [];
        foreach ($result['items'] as $item) {
            $usage[(int) $item['id']] = $this->roles->usersCount((int) $item['id']);
        }

        return Response::html(View::render('roles', [
            'active' => 'roles',
            'items'  => $result['items'],
            'result' => $result,
            'usage'  => $usage,
            'groups' => Permission::GROUPS,
        ], 'Роли'));
    }

    public function form(Request $request, ?int $id): Response
    {
        $role = $this->requireIfEditing($id, $id === null ? null : $this->roles->find($id));

        return Response::html(View::render('role_form', [
            'active' => 'roles',
            'role'   => $role,
            'groups' => Permission::GROUPS,
            'usage'  => $role === null ? 0 : $this->roles->usersCount((int) $role['id']),
        ], $role === null ? 'Новая роль' : 'Роль «' . $role['name'] . '»'));
    }

    public function save(Request $request): Response
    {
        $id = (int) $request->input('id', 0);

        $data = [
            'name'        => trim((string) $request->input('name', '')),
            'description' => trim((string) $request->input('description', '')),
            'permissions' => (array) $request->input('permissions', []),
        ];

        try {
            if ($id > 0) {
                $this->roles->update($id, $data);
                Audit::updated('role', $id, 'роль «' . $data['name'] . '», прав: ' . count($data['permissions']));
                View::flash('Роль сохранена');
            } else {
                $id = $this->roles->create($data);
                Audit::created('role', $id, 'роль «' . $data['name'] . '», прав: ' . count($data['permissions']));
                View::flash('Роль создана');
            }
        } catch (Throwable $e) {
            View::flash('Не сохранилось: ' . $e->getMessage(), 'error');

            return Response::redirect($id > 0 ? View::route('ui.roles.show', ['id' => $id]) : View::route('ui.roles.new'));
        }

        return Response::redirect(View::route('ui.roles.show', ['id' => $id]));
    }

    public function action(Request $request, int $id, string $action): Response
    {
        $role = $this->require($this->roles->find($id));

        if ($action !== 'delete') {
            View::flash('Неизвестное действие: ' . $action, 'error');

            return Response::redirect(View::route('ui.roles.show', ['id' => $id]));
        }

        try {
            $this->roles->delete($id);
            Audit::deleted('role', $id, 'роль «' . $role['name'] . '»');
            View::flash('Роль «' . $role['name'] . '» удалена');
        } catch (Throwable $e) {
            View::flash($e->getMessage(), 'error');

            return Response::redirect(View::route('ui.roles.show', ['id' => $id]));
        }

        return Response::redirect(View::route('ui.roles'));
    }

    protected function listRoute(): string
    {
        return 'ui.roles';
    }

    protected function notFoundMessage(): string
    {
        return 'Роль не найдена';
    }
}
