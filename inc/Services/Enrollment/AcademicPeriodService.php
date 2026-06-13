<?php

declare(strict_types=1);

namespace Inc\Services\Enrollment;

use Inc\DTO\Settings\AcademicPeriodDTO;
use Inc\DTO\Enrollment\StudentEnrollmentDTO;
use Inc\Repositories\OptionsRepositories\AcademicPeriodRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\OptionsRepositories\StudentPeriodMatrixRepository;
use Inc\Repositories\OptionsRepositories\UserRepository;

/**
 * Class AcademicPeriodService
 *
 * Сервис для работы с учебными периодами (годами/семестрами) и связями учеников.
 *
 * @package Inc\Services
 *
 * ### Основные обязанности:
 *
 * 1. **Управление периодами** — создание, обновление и удаление учебных периодов.
 * 2. **Получение учеников по периоду** — сбор всех учеников, привязанных к указанному периоду,
 *    с обогащением данных (класс, группа, личная информация).
 *
 * ### Архитектурная роль:
 *
 * Делегирует получение и сохранение данных специализированным репозиториям:
 * - AcademicPeriodRepository — информация об учебных периодах
 * - StudentPeriodMatrixRepository — связи ученик → период + класс
 * - GroupsRepository — информация о группах
 * - UserRepository — данные пользователей (учеников)
 *
 * Собирает и "компилирует" данные из разных источников в единый формат.
 */
readonly class AcademicPeriodService {

	/**
	 * Конструктор сервиса.
	 *
	 * @param AcademicPeriodRepository      $period_repository   Репозиторий учебных периодов
	 * @param UserRepository                $user_repository     Репозиторий пользователей
	 * @param StudentPeriodMatrixRepository $matrix_repository   Репозиторий связей ученик-период
	 * @param GroupsRepository              $group_repository    Репозиторий групп учеников
	 */
	public function __construct(
		private AcademicPeriodRepository $period_repository,
		private UserRepository $user_repository,
		private StudentPeriodMatrixRepository $matrix_repository,
		private GroupsRepository $group_repository,
	) {}

	/**
	 * Валидирует даты и сохраняет учебный период через репозиторий.
	 * Обеспечивает инвариант: не более одного текущего периода.
	 *
	 * @param AcademicPeriodDTO $dto DTO с данными периода
	 *
	 * @return bool
	 */
	public function savePeriod( AcademicPeriodDTO $dto ): bool {
		$start_ts = strtotime( $dto->start_date );
		$end_ts   = strtotime( $dto->end_date );

		if ( false === $start_ts || false === $end_ts || $start_ts > $end_ts ) {
			return false;
		}

		if ( $dto->is_current ) {
			$this->period_repository->clearAllCurrentFlags();
		}

		return $this->period_repository->save( $dto );
	}

	/**
	 * Удаляет учебный период по ID.
	 *
	 * @param string $id ID учебного периода
	 *
	 * @return bool
	 */
	public function deletePeriod( string $id ): bool {
		return $this->period_repository->remove( $id );
	}

	/**
	 * Получает учебный период по ID.
	 *
	 * @param string $id ID учебного периода
	 *
	 * @return AcademicPeriodDTO|null
	 */
	public function getById( string $id ): ?AcademicPeriodDTO {
		return $this->period_repository->getById( $id );
	}

	/**
	 * Возвращает список учеников, привязанных к учебному периоду,
	 * обогащённых данными о классе и группе.
	 *
	 * @param string $period_id ID учебного периода
	 *
	 * @return array
	 */
	public function getStudentsByPeriod( string $period_id ): array {
		// Проверка существования периода
		if ( null === $this->period_repository->getById( $period_id ) ) {
			return array();
		}

		/** @var StudentEnrollmentDTO[] $matrix */
		$matrix = $this->matrix_repository->getByPeriod( $period_id );

		if ( empty( $matrix ) ) {
			return array();
		}

		$result = array();

		foreach ( $matrix as $enrollment ) {
			$student_dto = $this->user_repository->getById( $enrollment->student_id );

			if ( null === $student_dto ) {
				continue;
			}

			$group_dto  = isset( $enrollment->group_id ) ? $this->group_repository->findById( (int) $enrollment->group_id ) : null;
			$group_name = $group_dto?->group_name ?? 'Без группы';

			$result[] = array(
				'id'         => $student_dto->id,
				'name'       => $student_dto->displayName,
				'email'      => $student_dto->email,
				'class_num'  => $enrollment->class_num,
				'group_name' => $group_name,
			);
		}

		return $result;
	}

	/**
	 * Разделяет массив академических периодов на текущий и остальные для вывода в UI.
	 *
	 * @param AcademicPeriodDTO[] $academic_periods Исходный массив DTO периодов из репозитория
	 *
	 * @return array{current: array{id: string, name: string}|null, other: array<string, string>}
	 */
	public function getSortedPeriods( array $academic_periods ): array {
		$current_period = null;
		$other_periods  = array();

		if ( ! empty( $academic_periods ) ) {
			foreach ( $academic_periods as $period_dto ) {
				if ( ! ( $period_dto instanceof AcademicPeriodDTO ) ) {
					continue;
				}

				if ( $period_dto->is_current ) {
					$current_period = array(
						'id'   => (string) $period_dto->id,
						'name' => (string) $period_dto->name,
					);
				} else {
					$other_periods[ (string) $period_dto->id ] = (string) $period_dto->name;
				}
			}
		}

		return array(
			'current' => $current_period,
			'other'   => $other_periods,
		);
	}
}
