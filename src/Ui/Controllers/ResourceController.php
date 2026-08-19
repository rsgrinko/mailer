<?php

declare(strict_types=1);

namespace Mailer\Ui\Controllers;

use Mailer\Ui\RecordNotFound;

/**
 * Общее для разделов панели, где есть список, карточка и действия над записью:
 * проекты, транспорты, шаблоны, пользователи, вебхуки.
 *
 * Раздел говорит, как называется запись и куда возвращать, если её не нашли, —
 * остальное одинаково.
 */
abstract class ResourceController
{
    /**
     * Имя маршрута со списком раздела: 'ui.projects'.
     */
    abstract protected function listRoute(): string;

    /**
     * Сообщение, когда записи нет: «Проект не найден».
     */
    abstract protected function notFoundMessage(): string;

    /**
     * Запись или возврат к списку с сообщением.
     *
     * @param array<string, mixed>|null $row
     * @return array<string, mixed>
     */
    protected function require(?array $row): array
    {
        if ($row === null) {
            throw new RecordNotFound($this->notFoundMessage(), $this->listRoute());
        }

        return $row;
    }

    /**
     * То же самое для формы: без id это создание новой записи, с id — правка существующей.
     *
     * @param array<string, mixed>|null $row
     * @return array<string, mixed>|null
     */
    protected function requireIfEditing(?int $id, ?array $row): ?array
    {
        return $id === null ? null : $this->require($row);
    }
}
