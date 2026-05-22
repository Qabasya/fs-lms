<?php

declare( strict_types=1 );

namespace Inc\Services;

use Inc\DTO\StudentGroupDTO;
use Inc\Repositories\StudentGroupRepository;
use Inc\Shared\Traits\SlugGenerator;

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

	use SlugGenerator;

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

		// 1. Транслитерация + санитизация через трейт SlugGenerator
		$slugized_title  = $this->slugify( $title, 'group' );
		$clean_period_id = $this->slugify( $period_id );

		// 2. Формируем базовый уникальный ID по маске: [название]_[слаг_периода]
		$base_id      = sprintf( '%s_%s', $slugized_title, $clean_period_id );
		$generated_id = $base_id;

		$counter = 1;

		// 3. Цикл защиты от коллизий: крутимся, пока не найдем свободный ID в репозитории
		while ( null !== $this->group_repository->getById( $generated_id ) ) {
			$counter++;
			// Маска коллизии: robo-1-2_2026_autumn
			$generated_id = sprintf( '%s-%d_%s', $slugized_title, $counter, $clean_period_id );
		}

		// 4. Формируем DTO с гарантированно уникальным ID
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