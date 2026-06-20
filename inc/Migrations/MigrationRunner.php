<?php

declare(strict_types=1);

namespace Inc\Migrations;

use Inc\Contracts\MigrationInterface;
use Inc\Enums\Settings\OptionName;

/**
 * Class MigrationRunner
 *
 * Оркестратор применения миграций схемы базы данных системы зачисления.
 *
 * @package Inc\Migrations
 *
 * ### Основные обязанности:
 *
 * 1. **Регистрация миграций** — добавление миграций в пул для последующего выполнения.
 * 2. **Применение миграций** — последовательное накатывание миграций, которые ещё не были применены.
 * 3. **Откат миграций** — полный откат всех зарегистрированных миграций в обратном порядке.
 *
 * ### Архитектурная роль:
 *
 * Является оркестратором (runner) для миграций базы данных.
 * Отслеживает текущую версию схемы в опции SchemaVersion и применяет только новые миграции.
 * Использует semver-сравнение версий (version_compare).
 */
class MigrationRunner {

	/**
	 * Список зарегистрированных миграций (версия → объект миграции).
	 *
	 * @var array<string, MigrationInterface>
	 */
	private array $migrations = array();

	/**
	 * Конструктор класса.
	 */
	public function __construct() {}

	/**
	 * Регистрирует миграцию в пуле.
	 *
	 * @param MigrationInterface $migration Объект миграции
	 *
	 * @return void
	 */
	public function register( MigrationInterface $migration ): void {
		$this->migrations[ $migration->version() ] = $migration;
	}

	/**
	 * Запускает все накопленные миграции, которые выше текущей версии в БД.
	 * Сортирует миграции по версии и применяет только новые.
	 *
	 * @return void
	 */
	public function run(): void {
		if ( empty( $this->migrations ) ) {
			return;
		}

		// uksort() — сортирует массив по ключам (версиям) с помощью пользовательской функции
		// version_compare() — сравнивает версии в формате semver (1.0.0, 2.1.3)
		uksort( $this->migrations, 'version_compare' );

		// get_option() — получает текущую версию схемы (по умолчанию '0.0.0')
		$currentVersion     = get_option( OptionName::SchemaVersion->value, '0.0.0' );
		$lastAppliedVersion = $currentVersion;

		foreach ( $this->migrations as $version => $migration ) {
			// Если версия миграции выше текущей версии в БД — накатываем её
			if ( version_compare( $version, $currentVersion, '>' ) ) {
				$migration->up();
				$lastAppliedVersion = $version;
			}
		}

		// Если были применены новые миграции, обновляем версию в опциях WordPress
		if ( version_compare( $lastAppliedVersion, $currentVersion, '>' ) ) {
			// update_option() — обновляет опцию с версией схемы
			update_option( OptionName::SchemaVersion->value, $lastAppliedVersion );
		}
	}

	/**
	 * Сбрасывает версию схемы до 0.0.0, чтобы при следующем run() все миграции
	 * применились заново. Использовать только в dev-окружении.
	 *
	 * @return void
	 */
	public function reset(): void {
		update_option( OptionName::SchemaVersion->value, '0.0.0' );
	}

	/**
	 * Выполняет полный откат всех зарегистрированных миграций.
	 * Откатывает в обратном порядке (от новых к старым).
	 *
	 * @return void
	 */
	public function rollback(): void {
		if ( empty( $this->migrations ) ) {
			return;
		}

		// Сортируем по версии по убыванию для корректного удаления зависимых таблиц
		uksort(
			$this->migrations,
			static function ( string $a, string $b ): int {
				// version_compare( $b, $a ) — сравнение в обратном порядке
				return version_compare( $b, $a );
			}
		);

		foreach ( $this->migrations as $migration ) {
			// down() — выполняет откат миграции (удаление таблиц, полей и т.д.)
			$migration->down();
		}

		// delete_option() — удаляет опцию с версией схемы после полного отката
		delete_option( OptionName::SchemaVersion->value );
	}
}