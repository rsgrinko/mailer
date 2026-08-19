<?php

declare(strict_types=1);

namespace Mailer\Http\Middleware;

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
    private ProjectRepository $projects;
    private Logger $logger;

    public function __construct(?ProjectRepository $projects = null, ?Logger $logger = null)
    {
        $this->projects = $projects ?? new ProjectRepository();
        $this->logger   = $logger ?? new Logger('api');
    }

    public function __invoke(Request $request, callable $next): Response
    {
        $key = $request->bearerToken();

        if ($key === '') {
            return Response::error('Не передан API-ключ. Ожидается заголовок Authorization: Bearer <ключ>', 401);
        }

        $project = $this->projects->findByApiKey($key);

        if ($project === null) {
            $this->logger->warning('Неверный API-ключ', ['ip' => $request->ip()]);

            return Response::error('Неверный API-ключ', 401);
        }

        if ((int) $project['active'] !== 1) {
            return Response::error('Проект «' . $project['name'] . '» отключён', 403);
        }

        return $next($request->setAttribute('project', $project));
    }
}
