<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\Enums\Access\Capability;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;

class GroupAccessGuard {

	public function __construct(
		private readonly GroupsRepository       $groups,
		private readonly StudentRecordRepository $studentRecords,
	) {}

	/** Может ли пользователь управлять группой (teacher_id || Admin). */
	public function canManage( int $groupId, int $userId ): bool {
		if ( user_can( $userId, Capability::Admin->value ) ) {
			return true;
		}
		$group = $this->groups->findById( $groupId );
		return $group && (int) $group->teacher_id === $userId;
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
