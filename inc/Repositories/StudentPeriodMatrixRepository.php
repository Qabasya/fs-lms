<?php

declare(strict_types=1);

namespace Inc\Repositories;

use Inc\DTO\StudentEnrollmentDTO;
use Inc\Enums\OptionName;

/**
 * Class StudentPeriodMatrixRepository
 *
 * Репозиторий для работы со связями "ученик → учебный период + класс".
 *
 * @package Inc\Repositories
 *
 * ### Основные обязанности:
 *
 * 1. **CRUD-операции** — чтение, сохранение и удаление связей учеников с учебными периодами.
 * 2. **Фильтрация по периоду** — получение всех связей для указанного учебного периода.
 * 3. **Уникальный ключ** — генерация составного ключа для хранения связей.
 *
 * ### Архитектурная роль:
 *
 * Инкапсулирует работу с опцией `fs_lms_student_year_meta` (или переименованной в STUDENT_PERIOD_META)
 * в wp_options. Хранит информацию о том, в каком классе (class_num) и группе учится ученик
 * в конкретном учебном периоде. Использует DTO StudentEnrollmentDTO для типобезопасной передачи данных.
 */
class StudentPeriodMatrixRepository {

	/**
	 * Конструктор репозитория.
	 */
	public function __construct() {}

	/**
	 * Возвращает все связи ученик-период.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function readAll(): array {
		// get_option() — получает опцию из таблицы wp_options
		$matrix = get_option( OptionName::STUDENT_PERIOD_META->value, array() );
		return is_array( $matrix ) ? $matrix : array();
	}

	/**
	 * Возвращает все связи для указанного учебного периода.
	 *
	 * @param string $period_id ID учебного периода
	 *
	 * @return StudentEnrollmentDTO[]
	 */
	public function getByPeriod( string $period_id ): array {
		return array_values(
			array_map(
				fn( array $meta ) => StudentEnrollmentDTO::fromArray( $meta ),
				// array_filter() — оставляет только записи с совпадающим period_id
				array_filter(
					$this->readAll(),
					fn( array $meta ) => $period_id === ( $meta['period_id'] ?? '' )
				)
			)
		);
	}

	/**
	 * Сохраняет (создаёт или обновляет) связь ученика с учебным периодом.
	 *
	 * @param StudentEnrollmentDTO $dto DTO с данными связи
	 *
	 * @return bool
	 */
	public function save( StudentEnrollmentDTO $dto ): bool {
		$matrix = $this->readAll();

		// storageKey() — генерирует уникальный ключ для хранения (например, "usr_123_2025_2026")
		$matrix[ $dto->storageKey() ] = $dto->toArray();

		// update_option() — обновляет опцию, возвращает false при ошибке или отсутствии изменений
		return (bool) update_option( OptionName::STUDENT_PERIOD_META->value, $matrix );
	}

	/**
	 * Удаляет связь ученика с учебным периодом.
	 *
	 * @param int    $student_id ID ученика
	 * @param string $period_id  ID учебного периода
	 *
	 * @return bool
	 */
	public function remove( int $student_id, string $period_id ): bool {
		$matrix = $this->readAll();

		// Генерация ключа по тому же правилу, что и в storageKey()
		$key = "usr_{$student_id}_{$period_id}";

		if ( ! isset( $matrix[ $key ] ) ) {
			return false;
		}

		// unset() — удаляет элемент из массива по ключу
		unset( $matrix[ $key ] );

		return (bool) update_option( OptionName::STUDENT_PERIOD_META->value, $matrix );
	}
}