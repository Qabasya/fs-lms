<?php

declare( strict_types=1 );

namespace Inc\Callbacks;

use Inc\Core\BaseController;
use Inc\Enums\Capability;
use Inc\Enums\Nonce;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Deletion\DeleteGroupEvent;
use Inc\Services\Deletion\DeletePeriodEvent;
use Inc\Services\Deletion\DeleteStudentEvent;
use Inc\Services\Deletion\DeletionEventDispatcher;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

class DeletionCallbacks extends BaseController {

	use Authorizer;
	use Sanitizer;

	public function __construct(
		private readonly DeletionEventDispatcher $dispatcher,
		private readonly GroupsRepository $groups,
		private readonly StudentRecordRepository $studentRecords,
	) {
		parent::__construct();
	}

	public function ajaxCheckGroupDeletion(): void {
		$this->authorize( Nonce::DeleteGroup, Capability::Admin );

		$groupId = $this->sanitizeInt( 'group_id' );

		$this->success( array(
			'student_count' => $this->studentRecords->countUniqueStudentsByGroup( $groupId ),
		) );
	}

	public function ajaxDeleteGroup(): void {
		$this->authorize( Nonce::DeleteGroup, Capability::Admin );

		$groupId = $this->sanitizeInt( 'group_id' );

		$this->dispatcher->dispatch( new DeleteGroupEvent( $groupId, get_current_user_id() ) );

		$this->success( array( 'message' => 'Группа удалена' ) );
	}

	public function ajaxCheckSubjectDeletion(): void {
		$this->authorize( Nonce::Subject, Capability::Admin );

		$subjectKey = $this->sanitizeKey( 'subject_key' );

		$dbGroups     = $this->groups->findBySubjectKey( $subjectKey );
		$groupCount   = count( $dbGroups );
		$studentCount = 0;

		foreach ( $dbGroups as $group ) {
			$studentCount += $this->studentRecords->countUniqueStudentsByGroup( (int) $group->id );
		}

		$this->success( array(
			'student_count' => $studentCount,
			'group_count'   => $groupCount,
		) );
	}

	public function ajaxCheckPeriodDeletion(): void {
		$this->authorize( Nonce::DeletePeriod, Capability::Admin );

		$periodId = $this->sanitizeKey( 'period_id' );

		$dbGroups     = $this->groups->findByPeriodId( $periodId );
		$groupCount   = count( $dbGroups );
		$studentCount = 0;

		foreach ( $dbGroups as $group ) {
			$studentCount += $this->studentRecords->countUniqueStudentsByGroup( (int) $group->id );
		}

		$this->success( array(
			'student_count' => $studentCount,
			'group_count'   => $groupCount,
		) );
	}

	public function ajaxDeletePeriod(): void {
		$this->authorize( Nonce::DeletePeriod, Capability::Admin );

		$periodId = $this->sanitizeKey( 'period_id' );

		$this->dispatcher->dispatch( new DeletePeriodEvent( $periodId, get_current_user_id() ) );

		$this->success( array( 'message' => 'Период удалён' ) );
	}

	public function ajaxHardDeleteStudent(): void {
		$this->authorize( Nonce::HardDeleteStudent, Capability::Admin );

		$studentPersonId = $this->sanitizeInt( 'person_id' );

		$this->dispatcher->dispatch( new DeleteStudentEvent( $studentPersonId, get_current_user_id() ) );

		$this->success( array( 'message' => 'Ученик удалён' ) );
	}
}
