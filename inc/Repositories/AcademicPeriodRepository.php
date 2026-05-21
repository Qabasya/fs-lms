<?php

declare(strict_types=1);

namespace Inc\Repositories;

use Inc\DTO\AcademicPeriodDTO;
use Inc\Enums\OptionName;

/**
 * Class AcademicPeriodRepository
 *
 * Репозиторий для работы с учебными периодами (годами/семестрами).
 *
 * @package Inc\Repositories
 *
 * ### Основные обязанности:
 *
 * 1. **CRUD-операции** — чтение, сохранение и удаление учебных периодов.
 * 2. **Управление текущим периодом** — автоматическое снятие флага is_current
 *    при сохранении нового текущего периода.
 * 3. **Очистка данных** — фильтрация некорректных записей при чтении.
 *
 * ### Архитектурная роль:
 *
 * Инкапсулирует работу с опцией `fs_lms_academic_periods` в wp_options.
 * Обрабатывает данные как структурированный массив согласно правилам проекта.
 * Использует DTO AcademicPeriodDTO для типобезопасной передачи данных.
 */
class AcademicPeriodRepository {

	/**
	 * Конструктор репозитория.
	 */
	public function __construct() {}

	/**
	 * Возвращает все учебные периоды. Фильтрует записи с отсутствующим/несовпадающим ID
	 * или пустым именем. Не выполняет запись и не модифицирует данные при чтении.
	 *
	 * @return array<string, array<string, mixed>> Массив периодов [id => данные]
	 */
	public function readAll(): array {
		// get_option() — получает опцию из таблицы wp_options
		$periods = get_option( OptionName::ACADEMIC_PERIODS->value, array() );

		if ( ! is_array( $periods ) ) {
			return array();
		}

		$clean = array();

		foreach ( $periods as $key => $period ) {
			// Санитизация ID и имени
			$id   = isset( $period['id'] ) ? trim( (string) $period['id'] ) : '';
			$name = isset( $period['name'] ) ? trim( (string) $period['name'] ) : '';

			// Пропускаем некорректные записи (ID пуст, имя пусто, ключ не совпадает с ID)
			if ( empty( $id ) || empty( $name ) || (string) $key !== $id ) {
				continue;
			}

			$clean[ $id ] = $period;
		}

		return $clean;
	}

	/**
	 * Получает учебный период по ID.
	 *
	 * @param string $id ID учебного периода (например, '2025_2026')
	 *
	 * @return AcademicPeriodDTO|null
	 */
	public function getById( string $id ): ?AcademicPeriodDTO {
		$periods = $this->readAll();
		$data    = $periods[ trim( $id ) ] ?? null;

		// fromArray() — фабричный метод DTO для создания из массива
		return $data ? AcademicPeriodDTO::fromArray( $data ) : null;
	}

	/**
	 * Получает текущий активный учебный период.
	 *
	 * @return AcademicPeriodDTO|null
	 */
	public function getCurrentPeriod(): ?AcademicPeriodDTO {
		foreach ( $this->readAll() as $period ) {
			if ( true === ( $period['is_current'] ?? false ) ) {
				return AcademicPeriodDTO::fromArray( $period );
			}
		}

		return null;
	}

	/**
	 * Сохраняет или обновляет учебный период. Если период помечен как текущий,
	 * снимает флаг is_current со всех остальных периодов (уникальность текущего).
	 *
	 * @param AcademicPeriodDTO $dto DTO с данными периода
	 *
	 * @return bool
	 */
	public function save( AcademicPeriodDTO $dto ): bool {
		$periods = $this->readAll();

		// Если сохраняемый период помечен как текущий — сбрасываем флаг у всех остальных
		if ( $dto->is_current ) {
			foreach ( $periods as $key => $period ) {
				$periods[ $key ]['is_current'] = false;
			}
		}

		// Сохраняем период (toArray() — преобразует DTO в массив)
		$periods[ $dto->id ] = $dto->toArray();

		// update_option() — обновляет опцию, возвращает false при ошибке или отсутствии изменений
		return (bool) update_option( OptionName::ACADEMIC_PERIODS->value, $periods );
	}

	/**
	 * Удаляет учебный период по ID.
	 *
	 * @param string $id ID учебного периода
	 *
	 * @return bool
	 */
	public function remove( string $id ): bool {
		$periods = $this->readAll();

		if ( ! isset( $periods[ $id ] ) ) {
			return false;
		}

		// unset() — удаляет элемент из массива по ключу
		unset( $periods[ $id ] );

		return (bool) update_option( OptionName::ACADEMIC_PERIODS->value, $periods );
	}
}