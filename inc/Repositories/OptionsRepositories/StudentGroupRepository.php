<?php

declare( strict_types=1 );

namespace Inc\Repositories\OptionsRepositories;

use Inc\DTO\Enrollment\StudentGroupDTO;
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
 * учебному периоду и предмету.
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
		$groups = get_option( OptionName::StudentGroups->value, array() );

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

		return $data ? StudentGroupDTO::fromArray( $data ) : null;
	}

	/**
	 * Возвращает группы учеников, отфильтрованные только по учебному периоду.
	 *
	 * @param string $period_id ID учебного периода
	 *
	 * @return StudentGroupDTO[]
	 */
	public function getByPeriod( string $period_id ): array {
		$all_groups     = $this->readAll();
		$filtered       = array_filter( $all_groups, fn( array $g ) => ( $g['period_id'] ?? '' ) === $period_id );
		$mapped_to_dtos = array_map( fn( array $g ) => StudentGroupDTO::fromArray( $g ), $filtered );

		return array_values( $mapped_to_dtos );
	}

	/**
	 * Возвращает группы учеников, отфильтрованные по учебному периоду и предмету.
	 *
	 * @param string $period_id  ID учебного периода
	 * @param string $subject_id ID предмета
	 *
	 * @return StudentGroupDTO[]
	 */
	public function getByPeriodAndSubject( string $period_id, string $subject_id ): array {
		$all_groups = $this->readAll();

		$filtered = array_filter(
			$all_groups,
			fn( array $g ) => ( $g['period_id'] ?? '' ) === $period_id && ( $g['subject_id'] ?? '' ) === $subject_id
		);

		$mapped_to_dtos = array_map( fn( array $g ) => StudentGroupDTO::fromArray( $g ), $filtered );

		return array_values( $mapped_to_dtos );
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

		$groups[ $dto->id ] = $dto->toArray();

		return (bool) update_option( OptionName::StudentGroups->value, $groups );
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

		unset( $groups[ $id ] );

		return (bool) update_option( OptionName::StudentGroups->value, $groups );
	}

	/**
	 * Удаляет все группы, привязанные к указанному предмету.
	 * Возвращает ID удалённых групп для последующей очистки матрицы.
	 *
	 * @param string $subject_key Ключ предмета
	 *
	 * @return string[] ID удалённых групп
	 */
	public function removeBySubject( string $subject_key ): array {
		$groups      = $this->readAll();
		$removed_ids = array();
		$remaining   = array();

		foreach ( $groups as $id => $data ) {
			if ( ( $data['subject_id'] ?? '' ) === $subject_key ) {
				$removed_ids[] = $id;
			} else {
				$remaining[ $id ] = $data;
			}
		}

		if ( ! empty( $removed_ids ) ) {
			update_option( OptionName::StudentGroups->value, $remaining );
		}

		return $removed_ids;
	}
}
