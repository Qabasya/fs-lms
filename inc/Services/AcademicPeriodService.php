<?php

declare(strict_types=1);

namespace Inc\Services;

use Inc\Repositories\AcademicPeriodRepository;
use Inc\Repositories\StudentGroupRepository;
use Inc\Repositories\StudentPeriodMatrixRepository;
use Inc\Repositories\UserRepository;

/**
 * Class AcademicPeriodService
 *
 * Сервис для работы с учебными периодами и связями учеников.
 *
 * @package Inc\Services
 *
 * ### Основные обязанности:
 *
 * 1. **Получение учеников по периоду** — сбор всех учеников, привязанных к указанному учебному периоду,
 *    с обогащением данных (класс, группа, личная информация).
 *
 * ### Архитектурная роль:
 *
 * Делегирует получение данных специализированным репозиториям:
 * - AcademicPeriodRepository — информация об учебном периоде
 * - StudentPeriodMatrixRepository — связи ученик → период + класс
 * - StudentGroupRepository — информация о группах
 * - UserRepository — данные пользователей (учеников)
 *
 * Собирает и "компилирует" данные из разных источников в единый формат.
 */
class AcademicPeriodService {

	public function __construct(
		private AcademicPeriodRepository $period_repository,
		private UserRepository $user_repository,
		private StudentPeriodMatrixRepository $matrix_repository,
		private StudentGroupRepository $group_repository,
	) {}

	/**
	 * Получает список учеников, распределённых на конкретный учебный период,
	 * включая информацию об их классе и группе в этом периоде.
	 *
	 * @param string $period_id ID учебного периода (например, '2025_2026')
	 *
	 * @return array Массив откомпилированных данных учеников
	 */
	public function getStudentsByPeriod( string $period_id ): array {
		$period = $this->period_repository->getById( $period_id );
		if ( null === $period ) {
			return array();
		}

		$matrix = $this->matrix_repository->getByPeriod( $period_id );
		if ( empty( $matrix ) ) {
			return array();
		}

		$all_groups = $this->group_repository->readAll();

		$filtered_students = array();

		foreach ( $matrix as $meta ) {
			$student_id  = (int) ( $meta['student_id'] ?? 0 );
			$student_dto = $this->user_repository->getById( $student_id );
			if ( null === $student_dto ) {
				continue;
			}

			$group_id   = $meta['group_id'] ?? '';
			$group_name = $all_groups[ $group_id ]['name'] ?? 'Без группы';

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
