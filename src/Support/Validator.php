<?php

declare(strict_types=1);

namespace Mailer\Support;

/**
 * Проверка входных данных. Все ошибки складываем в массив и отдаём одним исключением,
 * чтобы клиент увидел сразу все проблемы, а не по одной.
 */
final class Validator
{
    /** @var array<int, string> */
    private array $errors = [];

    /**
     * Корректный ли e-mail. Кроме filter_var проверяем длину и наличие домена с точкой.
     */
    public static function isEmail(string $email): bool
    {
        $email = trim($email);
        if ($email === '' || strlen($email) > 254) {
            return false;
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }

        $domain = substr(strrchr($email, '@') ?: '', 1);

        return $domain !== '' && str_contains($domain, '.');
    }

    /**
     * Добавить ошибку.
     */
    public function add(string $message): void
    {
        $this->errors[] = $message;
    }

    /**
     * Если условие ложно — записываем ошибку.
     */
    public function check(bool $condition, string $message): void
    {
        if (!$condition) {
            $this->add($message);
        }
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    /**
     * @return array<int, string>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Бросает исключение, если есть хоть одна ошибка.
     */
    public function throwIfFails(): void
    {
        if ($this->fails()) {
            throw new ValidationException($this->errors);
        }
    }
}
