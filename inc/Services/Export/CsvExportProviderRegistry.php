<?php

declare( strict_types=1 );

namespace Inc\Services\Export;

use Inc\Contracts\CsvExportProviderInterface;
use Inc\Enums\ExportTarget;
use RuntimeException;

/**
 * Class CsvExportProviderRegistry
 *
 * Реестр провайдеров CSV-экспорта.
 *
 * @package Inc\Services\Export
 *
 * ### Основные обязанности:
 *
 * 1. **Регистрация провайдеров** — добавление провайдеров с привязкой к ExportTarget.
 * 2. **Разрешение провайдера** — получение провайдера по типу экспорта.
 * 3. **Проверка существования** — наличие провайдера для заданного типа.
 *
 * ### Архитектурная роль:
 *
 * Реализует паттерн Registry для провайдеров CSV-экспорта.
 * Маппинг ExportTarget → CsvExportProviderInterface.
 *
 * ### Принципы:
 *
 * - **OCP (Open/Closed Principle)** — добавление нового экспорта не требует изменения
 *   существующего кода (только новый провайдер + регистрация).
 * - Каждый провайдер отвечает за один тип экспорта (Single Responsibility).
 * - Оркестратор (ExportService) использует реестр для получения провайдера.
 *
 * ### Пример использования:
 *
 * ```php
 * $registry->register( ExportTarget::Groups, $groupsProvider );
 * $registry->register( ExportTarget::Students, $studentsProvider );
 * $provider = $registry->resolve( ExportTarget::Groups );
 * ```
 */
class CsvExportProviderRegistry {

	/**
	 * Массив провайдеров (ключ — значение ExportTarget).
	 *
	 * @var array<string, CsvExportProviderInterface>
	 */
	private array $providers = array();

	/**
	 * Конструктор реестра.
	 */
	public function __construct() {}

	/**
	 * Регистрирует провайдер для указанного типа экспорта.
	 *
	 * @param ExportTarget                $target   Тип экспорта
	 * @param CsvExportProviderInterface $provider Провайдер
	 *
	 * @return void
	 */
	public function register( ExportTarget $target, CsvExportProviderInterface $provider ): void {
		$this->providers[ $target->value ] = $provider;
	}

	/**
	 * Возвращает провайдера для указанного типа экспорта.
	 *
	 * @param ExportTarget $target Тип экспорта
	 *
	 * @throws RuntimeException Если провайдер не зарегистрирован
	 *
	 * @return CsvExportProviderInterface
	 */
	public function resolve( ExportTarget $target ): CsvExportProviderInterface {
		$provider = $this->providers[ $target->value ] ?? null;
		if ( null === $provider ) {
			throw new RuntimeException( "Нет провайдера для ExportTarget::{$target->name}" );
		}
		return $provider;
	}

	/**
	 * Проверяет, зарегистрирован ли провайдер для указанного типа экспорта.
	 *
	 * @param ExportTarget $target Тип экспорта
	 *
	 * @return bool
	 */
	public function has( ExportTarget $target ): bool {
		return isset( $this->providers[ $target->value ] );
	}
}