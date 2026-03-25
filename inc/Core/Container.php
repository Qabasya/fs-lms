<?php

    namespace Inc\Core;

    // позволяет анализировать структуру класса во время выполнения программы
    use ReflectionClass;
    // используется для выбрасывания ошибок, если контейнер не может создать зависимость.
    use Exception;
    use ReflectionNamedType;

    /** Autowiring DI container
     * Класс DI-контейнера
     * для реализации инверсии зависимостей
     */
    class Container
    {
        // Хранилище уже созданных объектов (lazy singleton)
        private array $instances = [];

        /*
         * Метод для создания объекта класса и внедрения зависимостей
         */
        public function get(string $class): object {
            // Если объект уже создан, просто отдаем его (синглтон)
            if (isset($this->instances[$class])) {
                return $this->instances[$class];
            }

            // Объект рефлекшн для получения данных о классе
            $reflection = new ReflectionClass($class);

            if (!$reflection->isInstantiable()) {
                throw new Exception("Класс {$class} нельзя создать");
            }

            // Проверяем, есть ли у класса конструктор
            $constructor = $reflection->getConstructor();


            // Если конструктора нет — просто создаем объект
            if ($constructor === null) {
                return $this->instances[$class] = $reflection->newInstance();
            }

            // Массив зависимостей - объекты, которые нужно передать в конструктор.
            $dependencies = [];

            // Получаем параметры и передаём их в конструктор
            foreach ($constructor->getParameters() as $parameter) {
                $type = $parameter->getType();

                if (!$type instanceof ReflectionNamedType) {
                    throw new Exception("Неподдерживаемый тип параметра {$parameter->getName()}");
                }

                if ($type->isBuiltin()) {

                    if ($parameter->isDefaultValueAvailable()) {
                        $dependencies[] = $parameter->getDefaultValue();
                        continue;
                    }

                    throw new Exception(
                        "Невозможно разрешить встроенный тип {$type->getName()} в {$class}"
                    );
                }

                // Рекурсивно создаем зависимость (если ей тоже что-то нужно)
                $dependencies[] = $this->get($type->getName());
            }

            $instance = $reflection->newInstanceArgs($dependencies);
            // Создаем объект с внедренными зависимостями и сохраняем в кеше
            return $this->instances[$class] = $instance;

        }

    }