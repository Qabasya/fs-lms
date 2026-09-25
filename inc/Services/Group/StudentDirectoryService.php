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
 * Read-модель пикера «Сводки по ученику» (Tasks.md, п. 2): группы, доступные
 * текущему пользователю, с их направлением (предметом) и активные ученики этих
 * групп. Каскад «направление → группа → ученик» и поиск по ФИО строит клиент —
 * объём небольшой, AJAX на каждый шаг не нужен.
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
	 * Группы с активными учениками и сами ученики (с привязкой к группам).
	 *
	 * @return array{
	 *     groups: array<int, array{id:int, name:string, subject_key:string, subject:string}>,
	 *     students: array<int, array{person_id:int, name:string, groups:int[]}>
	 * }
	 */
	public function forTeacher( int $userId, bool $allGroups ): array {
		$groups   = array();
		$students = array();
		foreach ( $this->teacherGroups->idsFor( $userId, $allGroups ) as $groupId ) {
			$records = $this->records->findActiveByGroupId( $groupId );
			$group   = empty( $records ) ? null : $this->groups->findById( $groupId );
			if ( null === $group ) {
				continue;
			}

			$subjectKey = (string) $group->subject_key;
			$groups[]   = array(
				'id'          => $groupId,
				'name'        => (string) $group->name,
				'subject_key' => $subjectKey,
				'subject'     => $this->subjects->getByKey( $subjectKey )?->name ?? $subjectKey,
			);

			foreach ( $records as $rec ) {
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

		usort( $groups, static fn( array $a, array $b ): int => strnatcasecmp( $a['name'], $b['name'] ) );

		return array(
			'groups'   => $groups,
			'students' => array_values( $students ),
		);
	}
}
