<?php

declare( strict_types=1 );

namespace Inc\Services\Group;

use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;

/**
 * Class GroupRosterService
 *
 * Ростер группы для экрана «Группы» профиля (Эпик 10, T10.7): активные ученики
 * (PII-безопасные snapshot-имена из `student_records`) + их индивидуальные занятия.
 *
 * @package Inc\Services\Group
 */
class GroupRosterService {

	public function __construct(
		private readonly StudentRecordRepository $records,
		private readonly GroupLessonRepository   $lessons,
	) {}

	/**
	 * @return array{students: array<int, array{
	 *   person_id:int, name:string,
	 *   individual: array<int, array{id:int, date:?string, label:string, status:string}>
	 * }>}
	 */
	public function forGroup( int $groupId ): array {
		$students = array();
		foreach ( $this->records->findActiveByGroupId( $groupId ) as $rec ) {
			$students[ $rec->studentPersonId ] = array(
				'person_id'  => $rec->studentPersonId,
				'name'       => trim( $rec->snapshotLastName . ' ' . $rec->snapshotFirstName ),
				'individual' => array(),
			);
		}

		// Индивидуальные занятия (kind='individual') — раскладываем по ученику.
		foreach ( $this->lessons->listByGroup( $groupId ) as $gl ) {
			if ( 'individual' !== $gl->kind || null === $gl->studentPersonId ) {
				continue;
			}
			if ( ! isset( $students[ $gl->studentPersonId ] ) ) {
				continue;
			}
			$students[ $gl->studentPersonId ]['individual'][] = array(
				'id'     => $gl->id,
				'date'   => $gl->scheduledAt ? substr( $gl->scheduledAt, 0, 16 ) : null,
				'label'  => $gl->label ?? '',
				'status' => $gl->status,
			);
		}

		return array( 'students' => array_values( $students ) );
	}
}
