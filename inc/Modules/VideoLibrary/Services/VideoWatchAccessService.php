<?php
/**
 * Video watch access policy.
 *
 * @package FS_LMS
 */

declare( strict_types=1 );

namespace Inc\Modules\VideoLibrary\Services;

use Inc\DTO\Course\GroupLessonDTO;
use Inc\Enums\Access\UserRole;
use Inc\Services\Course\LessonGateResolver;
use Inc\Services\Profile\ProfileViewResolver;

/** Authorizes recording telemetry using the viewer's own learner context. */
class VideoWatchAccessService {

	/** Receives the collaborators for this module operation. */
	public function __construct(
		private readonly ProfileViewResolver $profiles,
		private readonly LessonGateResolver $gate,
	) {}

	/** Allow telemetry only for a learner with access to this lesson. */
	public function canWatch( int $userId, GroupLessonDTO $lesson ): bool {
		$context  = $this->profiles->context( $userId );
		$students = match ( $context->role ) {
			UserRole::FSStudent => null !== $context->personId ? array( $context->personId ) : array(),
			UserRole::FSParent => array_column( $context->children, 'personId' ),
			default => array(),
		};
		foreach ( $students as $personId ) {
			if ( $lesson->kind->isIndividual() && $lesson->studentPersonId !== $personId ) {
				continue;
			}
			if ( $this->gate->resolveLesson( $personId, $lesson )->isAvailable() ) {
				return true;
			}
		}
		return false;
	}
}
