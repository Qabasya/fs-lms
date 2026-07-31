<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Course;

use Inc\Core\BaseController;
use Inc\Enums\Access\Capability;
use Inc\Enums\Wp\Nonce;
use Inc\Services\Course\GroupAccessGuard;
use Inc\Services\Course\StudentSummaryService;
use Inc\Services\Group\GroupRosterService;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class GroupRosterCallbacks
 *
 * AJAX ростера группы: состав активных учеников и сводка по ученику.
 *
 * @package Inc\Callbacks\Course
 */
class GroupRosterCallbacks extends BaseController {

	use Authorizer;
	use Sanitizer;

	/**
	 * @param GroupRosterService    $roster  Ростер группы
	 * @param StudentSummaryService $summary Сводка по ученику
	 * @param GroupAccessGuard      $guard   Доступ к группе
	 */
	public function __construct(
		private readonly GroupRosterService    $roster,
		private readonly StudentSummaryService $summary,
		private readonly GroupAccessGuard      $guard,
	) {
		parent::__construct();
	}

	/**
	 * Ростер группы для экрана «Группы» (Эпик 10 T10.7): активные ученики + их
	 * индивидуальные занятия. Params: group_id.
	 */
	public function ajaxGetGroupRoster(): void {
		$this->authorize( Nonce::SaveSchedule, Capability::ManageLmsTeaching );
		$groupId = $this->requireInt( 'group_id' );

		$this->requireGroupAccess( $groupId );

		$this->success( $this->roster->forGroup( $groupId ) );
	}

	/**
	 * Сводка по ученику (Эпик 10 T10.8, D8): занятия ученика с посещаемостью и
	 * результатами работ. Params: group_id, student_person_id.
	 */
	public function ajaxGetStudentSummary(): void {
		$this->authorize( Nonce::SaveSchedule, Capability::ManageLmsTeaching );
		$groupId  = $this->requireInt( 'group_id' );
		$personId = $this->requireInt( 'student_person_id' );

		$this->requireGroupAccess( $groupId );

		$this->success( $this->summary->forStudent( $groupId, $personId ) );
	}

	/**
	 * Прерывает ответ, если у текущего пользователя нет доступа к группе.
	 *
	 * @param int $groupId ID группы
	 *
	 * @return void
	 */
	private function requireGroupAccess( int $groupId ): void {
		if ( ! $this->guard->canManage( $groupId, get_current_user_id() ) ) {
			$this->error( __( 'Нет доступа к группе.', 'fs-lms' ) );
		}
	}
}
