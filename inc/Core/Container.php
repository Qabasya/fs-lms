<?php

namespace Inc\Core;

use Exception;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Class Container
 *
 * DI-контейнер с автопроводкой (autowiring) для управления зависимостями.
 * Автоматически анализирует конструкторы классов и рекурсивно создаёт все необходимые зависимости.
 *
 * Паттерны:
 * - ServiceInterface Locator — централизованное получение сервисов
 * - Lazy Singleton — объекты создаются один раз при первом запросе
 * - Dependency Injection — автоматическое внедрение зависимостей через конструктор
 *
 * @package Inc\Core
 *
 * @example
 * $container = new Container();
 * $admin = $container->get(AdminController::class); // Автоматически внедрит все зависимости
 */
class Container {
	/**
	 * Хранилище уже созданных объектов (кэш).
	 *
	 * Реализует паттерн Lazy Singleton — каждый объект создаётся только один раз.
	 *
	 * @var array<string, object> Массив, где ключ — имя класса, значение — объект
	 */
	private array $instances = [];

	/**
	 * Создаёт экземпляр класса с автоматическим внедрением зависимостей.
	 *
	 * Анализирует конструктор целевого класса, рекурсивно создаёт все зависимости
	 * и кэширует готовый объект для последующих вызовов.
	 *
	 * Процесс:
	 * 1. Проверка кэша — если объект уже создан, возвращает его
	 * 2. Анализ класса через Reflection
	 * 3. Проверка, можно ли создать экземпляр класса
	 * 4. Если конструктора нет — просто создаёт объект
	 * 5. Если конструктор есть — рекурсивно собирает зависимости для его параметров
	 * 6. Создаёт объект с внедрёнными зависимостями и сохраняет в кэше
	 *
	 * @param string $class Полное имя класса (с пространством имён)
	 *
	 * @return object Экземпляр запрошенного класса со всеми внедрёнными зависимостями
	 *
	 * @throws Exception Если класс нельзя создать (абстрактный, интерфейс)
	 * @throws Exception Если параметр конструктора имеет неподдерживаемый тип
	 * @throws Exception Если встроенный тип (string, int и т.д.) не имеет значения по умолчанию
	 */
	public function get( string $class ): object {
		// Если объект уже создан, просто отдаем его (синглтон)
		if ( isset( $this->instances[ $class ] ) ) {
			return $this->instances[ $class ];
		}

		// Объект рефлекшн для получения данных о классе
		$reflection = new ReflectionClass( $class );

		// Проверяем, можно ли создать экземпляр класса (не абстрактный, не интерфейс)
		if ( ! $reflection->isInstantiable() ) {
			throw new Exception( "Класс {$class} нельзя создать" );
		}

		// Проверяем, есть ли у класса конструктор
		$constructor = $reflection->getConstructor();

		// Если конструктора нет — просто создаем объект
		if ( $constructor === null ) {
			return $this->instances[ $class ] = $reflection->newInstance();
		}

		// Массив зависимостей — объекты, которые нужно передать в конструктор
		$dependencies = [];

		// Получаем параметры и собираем зависимости для каждого
		foreach ( $constructor->getParameters() as $parameter ) {
			$type = $parameter->getType();

			// Проверяем, что тип параметра — именованный (не union, не mixed и т.д.)
			if ( ! $type instanceof ReflectionNamedType ) {
				throw new Exception( "Неподдерживаемый тип параметра {$parameter->getName()}" );
			}

			// Если параметр — встроенный тип (string, int, bool, array и т.д.)
			if ( $type->isBuiltin() ) {
				// Пытаемся взять значение по умолчанию
				if ( $parameter->isDefaultValueAvailable() ) {
					$dependencies[] = $parameter->getDefaultValue();
					continue;
				}

				// Встроенный тип без значения по умолчанию — невозможно разрешить
				throw new Exception(
					"Невозможно разрешить встроенный тип {$type->getName()} в {$class}"
				);
			}

			// Рекурсивно создаем зависимость (если ей тоже что-то нужно)
			$dependencies[] = $this->get( $type->getName() );
		}

		// Создаём объект с внедрёнными зависимостями
		$instance = $reflection->newInstanceArgs( $dependencies );

		// Сохраняем в кэше для последующих вызовов
		return $this->instances[ $class ] = $instance;
	}
}