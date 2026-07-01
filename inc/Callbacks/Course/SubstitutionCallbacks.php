<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Course;

use Inc\Core\BaseController;
use Inc\Enums\Access\Capability;
use Inc\Enums\Access\UserRole;
use Inc\Enums\Wp\Nonce;
use Inc\Repositories\OptionsRepositories\UserRepository;
use Inc\Repositories\WPDBRepositories\RoomRepository;
use Inc\Services\Course\RoomAssignmentService;
use Inc\Services\Course\SubstitutionService;
use Inc\Shared\Traits\AjaxResponse;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

/**
 * AJAX единого экрана «Замены» (офис, Эпики 5 + 9): замена преподавателя
 * (`substitutions`) и замена кабинета на период (`group_lessons.room_id`).
 *
 * Всё под `Capability::ManageSchedule` (только офис/админ) — назначение замен
 * идёт из офисного профиля, не в кабинете препода (D5).
 *
 * @package Inc\Callbacks\Course
 */
class SubstitutionCallbacks extends BaseController {

	use AjaxResponse;
	use Authorizer;
	use Sanitizer;

	public function __construct(
		private readonly SubstitutionService   $service,
		private readonly UserRepository        $users,
		private readonly RoomRepository        $rooms,
		private readonly RoomAssignmentService $roomAssignment,
	) {
		parent::__construct();
	}

	public function ajaxAssignSubstitute(): void {
		$this->authorize( Nonce::Substitution, Capability::ManageSchedule );

		$groupId             = $this->requireInt( 'group_id' );
		$substituteTeacherId = $this->requireInt( 'substitute_teacher_id' );
		$validFrom           = $this->sanitizeText( 'valid_from' );
		$validTo             = $this->sanitizeText( 'valid_to' );
		$reason              = $this->sanitizeText( 'reason' ) ?: null;

		try {
			$id = $this->service->assign(
				$groupId,
				$substituteTeacherId,
				$validFrom,
				$validTo,
				$reason,
				get_current_user_id()
			);
		} catch ( \InvalidArgumentException $e ) {
			$this->error( $e->getMessage() );
			return;
		}

		$this->success( array( 'substitution_id' => $id ) );
	}

	public function ajaxRevokeSubstitute(): void {
		$this->authorize( Nonce::Substitution, Capability::ManageSchedule );

		$id = $this->requireInt( 'substitution_id' );
		$this->service->revoke( $id );

		$this->success( array( 'substitution_id' => $id ) );
	}

	public function ajaxGetGroupSubstitutions(): void {
		$this->authorize( Nonce::Substitution, Capability::ManageSchedule );

		$groupId = $this->requireInt( 'group_id' );
		$this->success( $this->mapSubstitutions( $groupId ) );
	}

	/**
	 * Данные экрана «Замены» одной группы: замены + список преподавателей + кабинеты.
	 * Params: group_id
	 */
	public function ajaxGetSubstitutionsData(): void {
		$this->authorize( Nonce::Substitution, Capability::ManageSchedule );

		$groupId = $this->requireInt( 'group_id' );

		$teachers = array_map(
			static fn( $t ) => array( 'id' => (int) $t->id, 'name' => $t->displayName ),
			$this->users->getByRole( UserRole::FSTeacher )
		);
		$rooms = array_map(
			static fn( $r ) => array( 'id' => $r->id, 'name' => $r->name ),
			$this->rooms->findAll( true )
		);

		$this->success( array(
			'substitutions' => $this->mapSubstitutions( $groupId ),
			'teachers'      => $teachers,
			'rooms'         => $rooms,
		) );
	}

	/**
	 * Замена кабинета на период (ремонт): проставить/снять `room_id` занятиям группы в [from,to].
	 * Params: group_id, room_id (''=снять), valid_from, valid_to
	 */
	public function ajaxSetRoomOverride(): void {
		$this->authorize( Nonce::Substitution, Capability::ManageSchedule );

		$groupId   = $this->requireInt( 'group_id' );
		$roomId    = $this->sanitizeInt( 'room_id' ) ?: null;
		$validFrom = $this->sanitizeText( 'valid_from' );
		$validTo   = $this->sanitizeText( 'valid_to' );

		if ( '' === $validFrom || '' === $validTo ) {
			$this->error( 'Укажите период замены кабинета.' );
		}

		try {
			$result = $this->roomAssignment->overrideForRange( $groupId, $roomId, $validFrom, $validTo );
		} catch ( \InvalidArgumentException $e ) {
			$this->error( $e->getMessage() );
			return;
		}

		$this->success( $result );
	}

	/** @return array<int,array<string,mixed>> */
	private function mapSubstitutions( int $groupId ): array {
		return array_map(
			static fn( $s ) => array(
				'id'                      => $s->id,
				'group_id'                => $s->groupId,
				'original_teacher_id'     => $s->originalTeacherId,
				'original_teacher_name'   => $s->originalTeacherId
					? ( get_userdata( $s->originalTeacherId )->display_name ?? '' )
					: '',
				'substitute_teacher_id'   => $s->substituteTeacherId,
				'substitute_teacher_name' => get_userdata( $s->substituteTeacherId )->display_name ?? '',
				'valid_from'              => $s->validFrom,
				'valid_to'                => $s->validTo,
				'reason'                  => $s->reason,
			),
			$this->service->listByGroup( $groupId )
		);
	}
}
