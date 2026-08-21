<?php

declare( strict_types=1 );

namespace Inc\Services\Group;

use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Course\TeacherGroupResolver;

/**
 * Class StudentDirectoryService
 *
 * Read-модель стартового пикера «Сводки по ученику» (Tasks.md, п.1-2):
 * список всех активных учеников, доступных текущему пользователю (across
 * his groups), и курсы (группы) конкретного ученика в этом же разрезе
 * доступа — чтобы выбирать сначала ученика, а не группу.
 *
 * @package Inc\Services\Group
 */
class StudentDirectoryService {

	public function __construct(
		private readonly TeacherGroupResolver $teacherGroups,
		private readonly StudentRecordRepository $records,
		private readonly GroupsRepository $groups,
		private readonly SubjectRepository $subjects,
	) {}

	/**
	 * Активные ученики всех групп, доступных пользователю, с привязкой к группам
	 * (для клиентского фильтра по вводу — без AJAX-поиска, объём небольшой).
	 *
	 * @return array<int, array{person_id:int, name:string, groups:int[]}>
	 */
	public function forTeacher( int $userId, bool $allGroups ): array {
		$students = array();
		foreach ( $this->teacherGroups->idsFor( $userId, $allGroups ) as $groupId ) {
			foreach ( $this->records->findActiveByGroupId( $groupId ) as $rec ) {
				if ( ! isset( $students[ $rec->studentPersonId ] ) ) {
					$students[ $rec->studentPersonId ] = array(
						'person_id' => $rec->studentPersonId,
						'name'      => trim( $rec->snapshotLastName . ' ' . $rec->snapshotFirstName ),
						'groups'    => array(),
					);
				}
				$students[ $rec->studentPersonId ]['groups'][] = $groupId;
			}
		}

		return array_values( $students );
	}

	/**
	 * Группы (курсы) ученика, пересечённые с группами, доступными пользователю.
	 *
	 * @return array<int, array{group_id:int, name:string, subject:string}>
	 */
	public function coursesForStudent( int $personId, int $userId, bool $allGroups ): array {
		$accessible = array_flip( $this->teacherGroups->idsFor( $userId, $allGroups ) );

		$out = array();
		foreach ( $this->records->findActiveByStudent( $personId ) as $rec ) {
			if ( isset( $out[ $rec->groupId ] ) || ! isset( $accessible[ $rec->groupId ] ) ) {
				continue;
			}
			$group = $this->groups->findById( $rec->groupId );
			if ( null === $group ) {
				continue;
			}
			$out[ $rec->groupId ] = array(
				'group_id' => $rec->groupId,
				'name'     => $group->name,
				'subject'  => $this->subjects->getByKey( (string) $group->subject_key )?->name ?? (string) $group->subject_key,
			);
		}

		return array_values( $out );
	}
}
