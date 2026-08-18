<?php

declare(strict_types=1);

namespace Mailer\Support;

/**
 * Данные от клиента не прошли проверку. API отдаёт по такому исключению код 422.
 */
final class ValidationException extends MailerException
{
    /** @var array<int, string> */
    private array $errors;

    /**
     * @param array<int, string> $errors
     */
    public function __construct(array $errors)
    {
        $this->errors = array_values($errors);

        parent::__construct('Данные запроса не прошли проверку', ['errors' => $this->errors], 422);
    }

    /**
     * @return array<int, string>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
