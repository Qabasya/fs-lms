<?php

declare( strict_types=1 );

namespace Inc\Services;

use Inc\DTO\StudentGroupDTO;
use Inc\Repositories\StudentGroupRepository;

/**
 * Class StudentGroupService
 *
 * Сервис для управления бизнес-логикой групп учеников.
 *
 * @package Inc\Services
 *
 * ### Основные обязанности:
 *
 * 1. **Управление группами** — создание, валидация и удаление групп.
 * 2. **Автогенерация уникальных идентификаторов** — формирование слага на основе названия и периода.
 * 3. **Фильтрация** — получение групп, привязанных к конкретным учебным периодам.
 *
 * ### Архитектурная роль:
 *
 * Слоистый изолятор бизнес-логики. Делегирует непосредственное сохранение данных
 * репозиторию StudentGroupRepository, не совершая прямых вызовов WordPress API.
 */

readonly class StudentGroupService {
	/**
	 * Конструктор сервиса.
	 *
	 * @param StudentGroupRepository $group_repository Репозиторий групп учеников
	 */
	public function __construct(
		private StudentGroupRepository $group_repository
	) {
	}

	/**
	 * Возвращает список групп, отфильтрованных по учебному периоду.
	 *
	 * @param string $period_id ID учебного периода
	 *
	 * @return StudentGroupDTO[]
	 */
	public function getGroupsByPeriod( string $period_id ): array {
		if ( empty( $period_id ) ) {
			return array();
		}

		return $this->group_repository->getByPeriod( $period_id );
	}

	/**
	 * Сохраняет новую группу с автоматической генерацией уникального слага (ID).
	 *
	 * @param string $title      Название группы (например, "Робо-1")
	 * @param string $period_id  ID учебного периода
	 * @param string $subject_id ID предмета
	 * @param int    $teacher_id ID пользователя-преподавателя
	 *
	 * @return StudentGroupDTO|null Возвращает созданный DTO в случае успеха или null
	 */
	public function createGroup( string $title, string $period_id, string $subject_id, int $teacher_id ): ?StudentGroupDTO {
		if ( empty( $title ) || empty( $period_id ) || empty( $subject_id ) || $teacher_id <= 0 ) {
			return null;
		}

		// Автогенерация слага: перевод в транслит/нижний регистр + слаг периода
		$slugized_title = sanitize_title( $title );
		$generated_id   = sprintf( '%s-%s', $slugized_title, $period_id );

		// Проверяем, существует ли уже группа с таким сгенерированным ID
		if ( null !== $this->group_repository->getById( $generated_id ) ) {
			return null; // Группа с таким уникальным идентификатором уже создана
		}

		$dto = new StudentGroupDTO(
			id:         $generated_id,
			title:      $title,
			period_id:  $period_id,
			subject_id: $subject_id,
			teacher_id: $teacher_id
		);

		$is_saved = $this->group_repository->save( $dto );

		return $is_saved ? $dto : null;
	}

	/**
	 * Удаляет группу по её идентификатору.
	 *
	 * @param string $id ID группы
	 *
	 * @return bool
	 */
	public function deleteGroup( string $id ): bool {
		if ( empty( $id ) ) {
			return false;
		}

		return $this->group_repository->remove( $id );
	}
}