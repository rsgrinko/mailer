<?php

declare(strict_types=1);

namespace Mailer\Support;

use Mailer\Storage\Database;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Маленький сборщик объектов: создаёт класс, подставляя в конструктор то,
 * что он просит по типам. Нужен роутеру, чтобы контроллеры получали репозитории,
 * а не создавали их сами — тогда в тестах можно подсунуть свои.
 *
 * Никакой магии: только классы сервиса, значения по умолчанию и общий Database.
 */
final class Container
{
    /** @var array<string, object> Уже созданные объекты */
    private array $shared = [];

    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Подменить контейнер целиком (тесты).
     */
    public static function setInstance(?self $container): void
    {
        self::$instance = $container;
    }

    /**
     * Положить готовый объект — его и будут получать все, кто просит этот тип.
     */
    public function set(string $class, object $object): self
    {
        $this->shared[$class] = $object;

        return $this;
    }

    /**
     * Создаёт класс со всеми зависимостями.
     *
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    public function make(string $class): object
    {
        if (isset($this->shared[$class])) {
            /** @var T $shared */
            $shared = $this->shared[$class];

            return $shared;
        }

        // Подключение к базе одно на процесс
        if ($class === Database::class) {
            /** @var T $db */
            $db = Database::instance();

            return $db;
        }

        $reflection  = new ReflectionClass($class);
        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            /** @var T $object */
            $object = new $class();

            return $this->shared[$class] = $object;
        }

        $arguments = [];

        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType && !$type->isBuiltin() && str_starts_with($type->getName(), 'Mailer\\')) {
                $arguments[] = $this->make($type->getName());

                continue;
            }

            if ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();

                continue;
            }

            if ($parameter->allowsNull()) {
                $arguments[] = null;

                continue;
            }

            throw new MailerException(
                'Не могу собрать ' . $class . ': непонятно, что передать в «' . $parameter->getName() . '»'
            );
        }

        /** @var T $object */
        $object = $reflection->newInstanceArgs($arguments);

        return $this->shared[$class] = $object;
    }
}
