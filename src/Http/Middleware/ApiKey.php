<?php

declare(strict_types=1);

namespace Mailer\Http\Middleware;

use Mailer\Domain\Project;
use Mailer\Http\Request;
use Mailer\Http\Response;
use Mailer\Repository\ProjectRepository;
use Mailer\Support\Logger;

/**
 * Проверка API-ключа. Нашли проект — кладём его в запрос, дальше он приезжает
 * в контроллер аргументом $project.
 */
final class ApiKey
{
    private ?ProjectRepository $projects;
    private Logger $logger;

    public function __construct(?ProjectRepository $projects = null, ?Logger $logger = null)
    {
        $this->projects = $projects;
        $this->logger   = $logger ?? new Logger('api');
    }

    public function __invoke(Request $request, callable $next): Response
    {
        $key = $request->bearerToken();

        if ($key === '') {
            return Response::error('Не передан API-ключ. Ожидается заголовок Authorization: Bearer <ключ>', 401);
        }

        $row = $this->projects()->findByApiKey($key);

        if ($row === null) {
            $this->logger->warning('Неверный API-ключ', ['ip' => $request->ip()]);

            return Response::error('Неверный API-ключ', 401);
        }

        $project = Project::fromRow($row);

        if (!$project->active) {
            return Response::error('Проект «' . $project->name . '» отключён', 403);
        }

        return $next($request->setAttribute('project', $row));
    }

    /**
     * Репозиторий берём при первом обращении: собирать маршруты можно и с лежащей базой.
     */
    private function projects(): ProjectRepository
    {
        return $this->projects ??= new ProjectRepository();
    }
}
