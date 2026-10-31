<?php
/**
 * Staff ranking AJAX transport.
 *
 * @package FS_LMS
 */

declare( strict_types=1 );
namespace Inc\Callbacks\Profile;

use Inc\Core\BaseController;
use Inc\Enums\Access\UserRole;
use Inc\Enums\Wp\Nonce;
use Inc\Services\Profile\ProfileViewResolver;
use Inc\Services\Profile\RankingAccessService;
use Inc\Services\Profile\SubjectRankingService;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

/** Subject selection is checked again on the server for each request. */
class RankingCallbacks extends BaseController {
	use Authorizer;
	use Sanitizer;

	/** Receives the ranking view and permission policy. */
	public function __construct(
		private readonly SubjectRankingService $ranking,
		private readonly RankingAccessService $access,
		private readonly ProfileViewResolver $resolver,
	) {
		parent::__construct();
	}

	/**
	 * Returns subject ranks for the selected period; места — по направлению, а при выбранной
	 * группе — пересчитаны внутри неё.
	 *
	 * Два канала доступа: сотрудник — capability + нонс кабинета сотрудника; ученик —
	 * без capability (у него нет LMS-прав): нонс кабинета ученика + роль, направления
	 * ограничены его активными группами ({@see SubjectRankingService::buildForLearner()}).
	 */
	public function ajaxGetSubjectRanking(): void {
		$userId     = get_current_user_id();
		$capability = $this->access->capabilityFor( $userId );
		try {
			if ( null !== $capability ) {
				$this->authorize( Nonce::SaveSchedule, $capability );
				$this->success( $this->ranking->build( $userId, $this->sanitizeKey( 'subject_key' ), $this->sanitizeInt( 'group_id' ), $this->sanitizeText( 'period_from' ) ) );
			}

			Nonce::LearnerProfile->verify();
			$ctx = $this->resolver->context( $userId );
			if ( UserRole::FSStudent !== $ctx->role || null === $ctx->personId ) {
				$this->error( __( 'Рейтинг недоступен.', 'fs-lms' ) );
				return;
			}
			$this->success( $this->ranking->buildForLearner( $ctx->personId, $this->sanitizeKey( 'subject_key' ), $this->sanitizeInt( 'group_id' ), $this->sanitizeText( 'period_from' ) ) );
		} catch ( \DomainException | \InvalidArgumentException $exception ) {
			$this->error( $exception->getMessage() );
		}
	}
}
