<?php

declare( strict_types=1 );

namespace Inc\Services;

use Inc\DTO\Enrollment\StudentGroupDTO;
use Inc\Repositories\OptionsRepositories\StudentGroupRepository;
use Inc\Shared\Traits\SlugGenerator;

/**
 * Class StudentGroupService
 *
 * Сервис для управления группами студентов.
 *
 * @package Inc\Services
 *
 * ### Основные обязанности:
 *
 * 1. **Создание группы** — генерация уникального ID на основе названия и периода,
 *    валидация входных данных.
 * 2. **Удаление группы** — удаление группы из репозитория.
 * 3. **Получение групп по периоду** — список групп для указанного учебного периода.
 *
 * ### Архитектурная роль:
 *
 * Делегирует операции с БД StudentGroupRepository.
 * Использует трейт SlugGenerator для создания безопасных идентификаторов.
 *
 * ### Примечания:
 *
 * - ID группы генерируется автоматически в формате: {slug_title}_{period_id}
 * - При конфликте ID добавляется числовой суффикс: {slug_title}-2_{period_id}
 * - Все параметры валидируются перед созданием.
 */
readonly class StudentGroupService {

	use SlugGenerator;  // Трейт с методами slugify(), isValidSlug()

	/**
	 * Конструктор сервиса.
	 *
	 * @param StudentGroupRepository $group_repository Репозиторий групп
	 */
	public function __construct(
		private StudentGroupRepository $group_repository,
	) {}

	/**
	 * Возвращает список групп для указанного учебного периода.
	 *
	 * @param string $period_id ID учебного периода
	 *
	 * @return array
	 */
	public function getGroupsByPeriod( string $period_id ): array {
		if ( empty( $period_id ) ) {
			return array();
		}

		return $this->group_repository->getByPeriod( $period_id );
	}

	/**
	 * Создаёт новую группу студентов.
	 *
	 * @param string $title      Название группы
	 * @param string $period_id  ID учебного периода
	 * @param string $subject_id Ключ предмета
	 * @param int    $teacher_id ID преподавателя (пользователя WP)
	 * @param array  $schedule   Расписание занятий (массив)
	 *
	 * @return StudentGroupDTO|null
	 */
	public function createGroup( string $title, string $period_id, string $subject_id, int $teacher_id, array $schedule = [] ): ?StudentGroupDTO {
		// Валидация обязательных полей
		if ( empty( $title ) || empty( $period_id ) || empty( $subject_id ) || $teacher_id <= 0 ) {
			return null;
		}

		// slugify() — преобразование строки в безопасный идентификатор
		$slugized_title  = $this->slugify( $title, 'group' );
		$clean_period_id = $this->slugify( $period_id );

		// Генерация уникального ID
		$base_id      = sprintf( '%s_%s', $slugized_title, $clean_period_id );
		$generated_id = $base_id;
		$counter      = 1;

		// Разрешение конфликта ID (если группа с таким ID уже существует)
		while ( null !== $this->group_repository->getById( $generated_id ) ) {
			$counter++;
			$generated_id = sprintf( '%s-%d_%s', $slugized_title, $counter, $clean_period_id );
		}

		// Создание DTO
		$dto = new StudentGroupDTO(
			id:         $generated_id,
			title:      $title,
			period_id:  $period_id,
			subject_id: $subject_id,
			teacher_id: $teacher_id,
			schedule:   $schedule,
		);

		// Сохранение в репозиторий
		$is_saved = $this->group_repository->save( $dto );

		return $is_saved ? $dto : null;
	}

	/**
	 * Удаляет группу по ID.
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