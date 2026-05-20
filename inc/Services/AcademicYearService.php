<?php

declare(strict_types=1);

namespace Inc\Services;

use Inc\Repositories\AcademicYearRepository;
use Inc\Repositories\StudentGroupRepository;
use Inc\Repositories\StudentYearMatrixRepository;
use Inc\Repositories\UserRepository;

/**
 * Class AcademicYearService
 *
 * Сервис для работы с учебными годами и связями учеников.
 *
 * @package Inc\Services
 *
 * ### Основные обязанности:
 *
 * 1. **Получение учеников по году** — сбор всех учеников, привязанных к указанному учебному году,
 *    с обогащением данных (класс, группа, личная информация).
 *
 * ### Архитектурная роль:
 *
 * Делегирует получение данных специализированным репозиториям:
 * - AcademicYearRepository — информация об учебном годе
 * - StudentYearMatrixRepository — связи ученик → год + класс
 * - StudentGroupRepository — информация о группах
 * - UserRepository — данные пользователей (учеников)
 *
 * Собирает и "компилирует" данные из разных источников в единый формат.
 */
class AcademicYearService {

	public function __construct(
		private AcademicYearRepository $year_repository,
		private UserRepository $user_repository,
		private StudentYearMatrixRepository $matrix_repository,
		private StudentGroupRepository $group_repository,
	) {}

	/**
	 * Получает список учеников, распределённых на конкретный учебный год,
	 * включая информацию об их классе и группе в этом году.
	 *
	 * @param string $year_id ID учебного года (например, '2025_2026')
	 *
	 * @return array Массив откомпилированных данных учеников
	 */
	public function getStudentsByYear( string $year_id ): array {
		// Получение объекта учебного года
		$year = $this->year_repository->getById( $year_id );
		if ( null === $year ) {
			return array();
		}

		// Получение матрицы привязок учеников к году
		$matrix = $this->matrix_repository->getByYear( $year_id );
		if ( empty( $matrix ) ) {
			return array();
		}

		// Получение всех групп (для быстрого доступа по ID)
		$all_groups = $this->group_repository->readAll();

		$filtered_students = array();

		foreach ( $matrix as $meta ) {
			$student_id = (int) ( $meta['student_id'] ?? 0 );
			// Получение DTO ученика по ID
			$student_dto = $this->user_repository->getById( $student_id );
			if ( null === $student_dto ) {
				continue;  // Ученик не найден — пропускаем
			}

			$group_id = $meta['group_id'] ?? '';
			// Получение названия группы (с фолбеком)
			$group      = $this->group_repository->getById( $group_id );
			$group_name = $all_groups[ $group_id ]['name'] ?? 'Без группы';

			// Сборка итогового массива
			$filtered_students[] = array(
				'id'         => $student_dto->id,
				'name'       => $student_dto->displayName ?? '',
				'email'      => $student_dto->userEmail ?? '',
				'class_num'  => $meta['class_num'] ?? '',
				'group_name' => $group_name,
			);
		}

		return $filtered_students;
	}
}
