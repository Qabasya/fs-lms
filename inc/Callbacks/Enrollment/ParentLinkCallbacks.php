<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Enrollment;

use Inc\Core\BaseController;
use Inc\Enums\Access\Capability;
use Inc\Enums\Wp\MetaKeys;
use Inc\Enums\Wp\Nonce;
use Inc\Services\Enrollment\EnrollmentService;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class ParentLinkCallbacks
 *
 * Связка «родитель ↔ заявка»: назначение существующего родителя, снятие
 * назначения и поиск родителей для модалки select-parent-modal (события 3B, 4B).
 *
 * Выделен из EnrollmentCallbacks (Т14.2).
 *
 * @package Inc\Callbacks\Enrollment
 */
class ParentLinkCallbacks extends BaseController {

	use Authorizer;
	use Sanitizer;

	public function __construct(
		private readonly EnrollmentService $enrollmentService,
	) {
		parent::__construct();
	}

	/**
	 * AJAX: назначить существующего родителя к заявке (события 3B, 4B).
	 */
	public function ajaxSelectExistingParent(): void {
		$this->authorize( Nonce::SelectExistingParent, Capability::ManageApplications );

		$applicationId  = $this->requireInt( 'application_id', error: 'Не указан ID заявки.' );
		$parentPersonId = $this->requireInt( 'parent_person_id', error: 'Не указан ID родителя.' );

		try {
			$result = $this->enrollmentService->selectExistingParent( $applicationId, $parentPersonId );
			$this->success( $result->toArray() );
		} catch ( \InvalidArgumentException $e ) {
			$this->error( $e->getMessage() );
		} catch ( \DomainException $e ) {
			$this->error( $e->getMessage() );
		}
	}

	public function ajaxRemoveParentAssignment(): void {
		$this->authorize( Nonce::RemoveParentAssignment, Capability::ManageApplications );

		$applicationId = $this->requireInt( 'application_id', error: 'Не указан ID заявки.' );

		try {
			$result = $this->enrollmentService->removeParentAssignment( $applicationId );
			$this->success( $result->toArray() );
		} catch ( \InvalidArgumentException $e ) {
			$this->error( $e->getMessage() );
		} catch ( \DomainException $e ) {
			$this->error( $e->getMessage() );
		}
	}

	/**
	 * AJAX: поиск существующих родителей (для модалки select-parent-modal).
	 */
	public function ajaxSearchParents(): void {
		$this->authorize( Nonce::Manager, Capability::ManageApplications );

		$query = $this->sanitizeText( 'query' );

		$users = get_users( array(
			'role'   => 'lms_parent',
			'search' => '*' . $query . '*',
			'search_columns' => array( 'display_name', 'user_email', 'user_login' ),
			'number' => 20,
		) );

		$result = array();
		foreach ( $users as $user ) {
			$personId = (int) get_user_meta( $user->ID, MetaKeys::PersonID->value, true );
			$result[] = array(
				'person_id'    => $personId ?: null,
				'wp_user_id'   => $user->ID,
				'display_name' => $user->display_name,
				'email'        => $user->user_email,
			);
		}

		$this->success( $result );
	}
}
