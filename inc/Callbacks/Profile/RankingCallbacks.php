<?php
/**
 * Staff ranking AJAX transport.
 *
 * @package FS_LMS
 */

declare( strict_types=1 );
namespace Inc\Callbacks\Profile;

use Inc\Core\BaseController;
use Inc\Enums\Access\Capability;
use Inc\Enums\Wp\Nonce;
use Inc\Services\Profile\RankingAccessService;
use Inc\Services\Profile\SubjectRankingService;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

/** Subject selection is checked again on the server for each request. */
class RankingCallbacks extends BaseController {
	use Authorizer;
	use Sanitizer;

	/** Receives the ranking view and permission policy. */
	public function __construct( private readonly SubjectRankingService $ranking, private readonly RankingAccessService $access ) {
		parent::__construct();
	}

	/** Returns current subject ranks; a group filter never recalculates the places. */
	public function ajaxGetSubjectRanking(): void {
		$userId = get_current_user_id();
		$this->authorize( Nonce::SaveSchedule, $this->access->capabilityFor( $userId ) ?? Capability::ManageLmsTeaching );
		try {
			$this->success( $this->ranking->build( $userId, $this->sanitizeKey( 'subject_key' ), $this->sanitizeInt( 'group_id' ) ) );
		} catch ( \DomainException | \InvalidArgumentException $exception ) {
			$this->error( $exception->getMessage() );
		}
	}
}
