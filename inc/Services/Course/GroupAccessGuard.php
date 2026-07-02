<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\Enums\Access\Capability;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Repositories\WPDBRepositories\SubstitutionRepository;

class GroupAccessGuard {

	public function __construct(
		private readonly GroupsRepository        $groups,
		private readonly StudentRecordRepository $studentRecords,
		private readonly SubstitutionRepository  $substitutions,
	) {}

	/** Может ли пользователь управлять группой (teacher_id || Admin || активная замена). */
	public function canManage( int $groupId, int $userId ): bool {
		if (
			user_can( $userId, Capability::Admin->value ) ||
			user_can( $userId, Capability::ManageLmsPlatform->value )
		) {
			return true;
		}
		$group = $this->groups->findById( $groupId );
		if ( $group && (int) $group->teacher_id === $userId ) {
			return true;
		}
		// Замещающий получает доступ на срок grant; гаснет по valid_to (D5).
		return $this->substitutions->hasActiveGrant( $userId, $groupId );
	}

	/**
	 * Может ли пользователь ВЕСТИ журнал (посещаемость/оценки) СЕЙЧАС (T5.7).
	 *
	 * В отличие от {@see canManage} (чтение/КТП — доступны и постоянному преподу),
	 * запись в журнал в период активной замены закреплена за ФАКТИЧЕСКИМ преподом:
	 * постоянный препод (`groups.teacher_id`) переходит в read-only, писать может
	 * только замещающий (активный grant) + админ. По истечении замены — снова препод.
	 */
	public function canWriteJournal( int $groupId, int $userId ): bool {
		if (
			user_can( $userId, Capability::Admin->value ) ||
			user_can( $userId, Capability::ManageLmsPlatform->value )
		) {
			return true;
		}
		// Замещающий на срок grant — пишет.
		if ( $this->substitutions->hasActiveGrant( $userId, $groupId ) ) {
			return true;
		}
		// Постоянный препод пишет, ТОЛЬКО если сейчас нет активной замены по группе.
		$group = $this->groups->findById( $groupId );
		if ( $group && (int) $group->teacher_id === $userId ) {
			return null === $this->substitutions->findActiveForGroup( $groupId, current_time( 'Y-m-d' ) );
		}
		return false;
	}

	/** Есть ли у person хоть одна запись в группе (включая архивированные). */
	public function isMemberEver( int $groupId, int $personId ): bool {
		return (bool) $this->studentRecords->countByGroupAndPerson( $groupId, $personId );
	}

	/** Есть ли у person запись как у родителя члена группы. */
	public function isParentOf( int $groupId, int $parentPersonId ): bool {
		return (bool) $this->studentRecords->countByGroupAndParent( $groupId, $parentPersonId );
	}
}
