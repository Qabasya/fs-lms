<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Course;

use Inc\Core\BaseController;
use Inc\Enums\Access\Capability;
use Inc\Enums\Wp\Nonce;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\RoomRepository;
use Inc\Services\Course\RoomAssignmentService;
use Inc\Shared\Traits\AjaxResponse;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

/**
 * AJAX справочника кабинетов (офис, Эпик 9). Под `Capability::ManageLmsPlatform`.
 *
 * @package Inc\Callbacks\Course
 */
class RoomCallbacks extends BaseController {

	use AjaxResponse;
	use Authorizer;
	use Sanitizer;

	public function __construct(
		private readonly RoomRepository        $rooms,
		private readonly RoomAssignmentService $assignment,
		private readonly GroupsRepository      $groups,
		private readonly SubjectRepository     $subjects,
	) {
		parent::__construct();
	}

	public function ajaxGetRooms(): void {
		$this->authorize( Nonce::Room, Capability::ManageLmsPlatform );

		$rooms = array_map(
			static fn( $r ) => array(
				'id'               => $r->id,
				'name'             => $r->name,
				'seats'            => $r->seats,
				'allowed_subjects' => $r->allowedSubjects,
				'is_active'        => $r->isActive,
			),
			$this->rooms->findAll()
		);

		$roomNames = array();
		foreach ( $this->rooms->findAll() as $r ) {
			$roomNames[ $r->id ] = $r->name;
		}

		$groups = array_map(
			static fn( $g ) => array(
				'id'        => (int) $g->id,
				'name'      => $g->name,
				'subject'   => $g->subject_key,
				'room_id'   => isset( $g->room_id ) && '' !== $g->room_id ? (int) $g->room_id : null,
				'room_name' => isset( $g->room_id ) && $g->room_id ? ( $roomNames[ (int) $g->room_id ] ?? '' ) : '',
			),
			$this->groups->findAll()
		);

		$subjects = array();
		foreach ( $this->subjects->readActive() as $key => $dto ) {
			$subjects[ (string) $key ] = $dto->name;
		}

		$this->success( array( 'rooms' => $rooms, 'groups' => $groups, 'subjects' => $subjects ) );
	}

	public function ajaxSaveRoom(): void {
		$this->authorize( Nonce::Room, Capability::ManageLmsPlatform );

		$roomId   = $this->sanitizeInt( 'room_id' );
		$name     = $this->requireText( 'name' );
		$seats    = max( 0, $this->sanitizeInt( 'seats' ) );
		// Модалка кабинета не содержит поля активности → по умолчанию активен.
		$active   = ! isset( $_POST['is_active'] ) || $this->sanitizeBool( 'is_active' );
		$subjects = array_values( array_filter( array_map( 'sanitize_key', (array) ( $_POST['allowed_subjects'] ?? array() ) ) ) );

		$data = array( 'name' => $name, 'seats' => $seats, 'allowed_subjects' => $subjects, 'is_active' => $active );

		if ( $roomId > 0 ) {
			$this->rooms->update( $roomId, $data );
		} else {
			$roomId = $this->rooms->create( $data );
		}

		$this->success( array( 'room_id' => $roomId ) );
	}

	public function ajaxDeleteRoom(): void {
		$this->authorize( Nonce::Room, Capability::ManageLmsPlatform );

		$roomId = $this->requireInt( 'room_id' );
		$this->rooms->softDelete( $roomId );

		$this->success( array( 'room_id' => $roomId ) );
	}

	public function ajaxAssignGroupRoom(): void {
		$this->authorize( Nonce::Room, Capability::ManageLmsPlatform );

		$groupId = $this->requireInt( 'group_id' );
		$roomId  = $this->sanitizeInt( 'room_id' );

		try {
			$warnings = $this->assignment->assignToGroup( $groupId, $roomId > 0 ? $roomId : null );
		} catch ( \InvalidArgumentException $e ) {
			$this->error( $e->getMessage() );
			return;
		}

		$this->success( array( 'group_id' => $groupId, 'warnings' => $warnings ) );
	}
}
