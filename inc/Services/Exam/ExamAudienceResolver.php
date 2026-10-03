<?php

declare( strict_types=1 );

namespace Inc\Services\Exam;

use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;

/**
 * Резолвер аудитории проведения экзамена.
 *
 * **Аудитория** — все активные не пробные ученики групп предмета проведения (1.1).
 * Это потенциальные участники записи и получатели приглашений.
 *
 * **Управление** — право преподавателя на проведения своих предметов, методиста и администратора —
 * на любые (1.2). Это отдельное понятие от аудитории; разделено в {@see ExamAccessGuard}.
 *
 * Состав вычисляется при каждом вызове: зачисление, выбытие и смена группы учитываются сразу.
 * Закрытие «один-раз» приглашения для ученика (SPEC) реализуется на этапе 9 (дедупликация
 * уведомления с ключом приглашения), здесь ничего не хранится.
 *
 * @package Inc\Services\Exam
 */
class ExamAudienceResolver {

	public function __construct(
		private readonly GroupsRepository       $groups,
		private readonly StudentRecordRepository $records,
	) {}

	/**
	 * Уникальные ID учеников (активные не пробные записи в группах предмета).
	 *
	 * @param string $subjectKey Ключ предмета (например, 'inf_ege')
	 *
	 * @return int[] Отсортированный массив уникальных ID
	 */
	public function studentPersonIds( string $subjectKey ): array {
		$groups = $this->groups->findBySubjectKey( $subjectKey );
		$studentIds = array();

		foreach ( $groups as $group ) {
			// Пропускаем удалённые группы
			if ( null !== $group->deleted_at ) {
				continue;
			}

			$records = $this->records->findActiveByGroupId( $group->id );

			foreach ( $records as $record ) {
				// Пропускаем пробные записи
				if ( $record->isTrial ) {
					continue;
				}

				$studentIds[ $record->studentPersonId ] = true;
			}
		}

		$ids = array_keys( $studentIds );
		sort( $ids );
		return $ids;
	}

	/**
	 * Проверка: есть ли у ученика активная не пробная запись в группе этого предмета.
	 *
	 * @param int    $personId   ID ученика (person_id)
	 * @param string $subjectKey Ключ предмета
	 *
	 * @return bool
	 */
	public function isEligible( int $personId, string $subjectKey ): bool {
		$studentRecords = $this->records->findActiveByStudent( $personId );

		foreach ( $studentRecords as $record ) {
			// Пропускаем пробные записи
			if ( $record->isTrial ) {
				continue;
			}

			$group = $this->groups->findById( $record->groupId );
			if ( null === $group || null !== $group->deleted_at ) {
				continue;
			}

			if ( $group->subject_key === $subjectKey ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Уникальные ключи предметов активных не пробных записей ученика.
	 *
	 * Нужен «Моим экзаменам»: ученик видит проведения только этих предметов.
	 *
	 * @param int $personId ID ученика
	 *
	 * @return string[] Ключи предметов (уникальные, отсортированные)
	 */
	public function subjectKeysForStudent( int $personId ): array {
		$studentRecords = $this->records->findActiveByStudent( $personId );
		$subjectKeys = array();

		foreach ( $studentRecords as $record ) {
			// Пропускаем пробные записи
			if ( $record->isTrial ) {
				continue;
			}

			$group = $this->groups->findById( $record->groupId );
			if ( null === $group || null !== $group->deleted_at ) {
				continue;
			}

			$subjectKeys[ $group->subject_key ] = true;
		}

		$keys = array_keys( $subjectKeys );
		sort( $keys );
		return $keys;
	}

	/**
	 * ID опекунов ученика в группах этого предмета.
	 *
	 * Родитель получает просмотр и уведомления по проведениям своих детей.
	 * Массив уникальный и без нулей.
	 *
	 * @param int    $studentPersonId ID ученика
	 * @param string $subjectKey      Ключ предмета
	 *
	 * @return int[] Уникальные ID родителей (опекунов)
	 */
	public function guardianPersonIds( int $studentPersonId, string $subjectKey ): array {
		$studentRecords = $this->records->findActiveByStudent( $studentPersonId );
		$guardianIds = array();

		foreach ( $studentRecords as $record ) {
			if ( 0 === $record->parentPersonId || null === $record->parentPersonId ) {
				continue;
			}

			$group = $this->groups->findById( $record->groupId );
			if ( null === $group || null !== $group->deleted_at ) {
				continue;
			}

			if ( $group->subject_key === $subjectKey ) {
				$guardianIds[ $record->parentPersonId ] = true;
			}
		}

		$ids = array_keys( $guardianIds );
		sort( $ids );
		return $ids;
	}
}
