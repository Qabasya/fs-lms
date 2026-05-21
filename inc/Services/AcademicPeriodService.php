<?php

declare(strict_types=1);

namespace Inc\Services;

use Inc\DTO\AcademicPeriodDTO;
use Inc\DTO\StudentEnrollmentDTO;
use Inc\Repositories\AcademicPeriodRepository;
use Inc\Repositories\StudentGroupRepository;
use Inc\Repositories\StudentPeriodMatrixRepository;
use Inc\Repositories\UserRepository;

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
 * - StudentGroupRepository — информация о группах
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
	 * @param StudentGroupRepository        $group_repository    Репозиторий групп учеников
	 */
	public function __construct(
		private AcademicPeriodRepository $period_repository,
		private UserRepository $user_repository,
		private StudentPeriodMatrixRepository $matrix_repository,
		private StudentGroupRepository $group_repository,
	) {}

	/**
	 * Валидирует даты и сохраняет учебный период через репозиторий.
	 * Логика «только один текущий период» делегирована репозиторию.
	 *
	 * @param AcademicPeriodDTO $dto DTO с данными периода
	 *
	 * @return bool
	 */
	public function savePeriod( AcademicPeriodDTO $dto ): bool {
		// strtotime() — преобразует строку даты в Unix timestamp
		$start_ts = strtotime( $dto->start_date );
		$end_ts   = strtotime( $dto->end_date );

		// Валидация: даты должны быть корректными, начало не позже окончания
		if ( false === $start_ts || false === $end_ts || $start_ts > $end_ts ) {
			return false;
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

		// Получение всех групп (индексированный массив)
		$all_groups = $this->group_repository->readAll();
		$result     = array();

		foreach ( $matrix as $enrollment ) {
			// Получение DTO ученика по ID
			$student_dto = $this->user_repository->getById( $enrollment->student_id );

			if ( null === $student_dto ) {
				continue;
			}

			// Получение названия группы (с фолбеком)
			$group_name = $all_groups[ $enrollment->group_id ]['name'] ?? 'Без группы';

			// Сборка итогового массива
			$result[] = array(
				'id'         => $student_dto->id,
				'name'       => $student_dto->displayName ?? '',
				'email'      => $student_dto->email,
				'class_num'  => $enrollment->class_num,
				'group_name' => $group_name,
			);
		}

		return $result;
	}
}
