<?php

declare( strict_types=1 );

namespace Inc\Repositories;

use Inc\DTO\StudentGroupDTO;
use Inc\Enums\OptionName;

/**
 * Class StudentGroupRepository
 *
 * Репозиторий для работы с группами учеников.
 *
 * @package Inc\Repositories
 *
 * ### Основные обязанности:
 *
 * 1. **CRUD-операции** — чтение, сохранение и удаление групп учеников.
 * 2. **Фильтрация по периоду и предмету** — получение групп, относящихся к указанному
 *    учебному периоду и предмету.
 * 3. **Преобразование в DTO** — работа с типобезопасными объектами StudentGroupDTO.
 *
 * ### Архитектурная роль:
 *
 * Инкапсулирует работу с опцией `fs_lms_student_groups` в wp_options.
 * Хранит данные групп в структурированном виде (ID → данные).
 * Использует DTO StudentGroupDTO для передачи данных между слоями приложения.
 */
class StudentGroupRepository {

	/**
	 * Конструктор репозитория.
	 */
	public function __construct() {
	}

	/**
	 * Возвращает все группы учеников.
	 *
	 * @return array<string, array<string, mixed>> Массив групп [id => данные]
	 */
	public function readAll(): array {
		// get_option() — получает опцию из таблицы wp_options
		$groups = get_option( OptionName::STUDENT_GROUPS->value, array() );

		return is_array( $groups ) ? $groups : array();
	}

	/**
	 * Получает группу учеников по ID.
	 *
	 * @param string $id ID группы
	 *
	 * @return StudentGroupDTO|null
	 */
	public function getById( string $id ): ?StudentGroupDTO {
		$data = $this->readAll()[ $id ] ?? null;

		// fromArray() — фабричный метод DTO для создания из массива
		return $data ? StudentGroupDTO::fromArray( $data ) : null;
	}

	/**
	 * Возвращает группы учеников, отфильтрованные по учебному периоду и предмету.
	 *
	 * @param string $period_id ID учебного периода
	 * @param string $subject_key Ключ предмета
	 *
	 * @return StudentGroupDTO[]
	 */
	public function getByPeriodAndSubject( string $period_id, string $subject_key ): array {
		return array_values(
			array_map(
				fn( array $g ) => StudentGroupDTO::fromArray( $g ),
				// array_filter() — оставляет только группы с совпадающим period_id и subject_key
				array_filter(
					$this->readAll(),
					fn( array $g ) => $period_id === ( $g['period_id'] ?? '' )
					                  && $subject_key === ( $g['subject_key'] ?? '' )
				)
			)
		);
	}

	/**
	 * Сохраняет (создаёт или обновляет) группу учеников.
	 *
	 * @param StudentGroupDTO $dto DTO с данными группы
	 *
	 * @return bool
	 */
	public function save( StudentGroupDTO $dto ): bool {
		$groups = $this->readAll();

		// Сохраняем группу по её ID
		$groups[ $dto->id ] = $dto->toArray();

		// update_option() — обновляет опцию, возвращает false при ошибке или отсутствии изменений
		return (bool) update_option( OptionName::STUDENT_GROUPS->value, $groups );
	}

	/**
	 * Удаляет группу учеников по ID.
	 *
	 * @param string $id ID группы
	 *
	 * @return bool
	 */
	public function remove( string $id ): bool {
		$groups = $this->readAll();

		if ( ! isset( $groups[ $id ] ) ) {
			return false;
		}

		// unset() — удаляет элемент из массива по ключу
		unset( $groups[ $id ] );

		return (bool) update_option( OptionName::STUDENT_GROUPS->value, $groups );
	}
}