<?php
/**
 * Subject permissions for the staff ranking.
 *
 * @package FS_LMS
 */

declare( strict_types=1 );

namespace Inc\Services\Profile;

use Inc\Enums\Access\Capability;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;

/** Staff see subject-wide rankings only for directions they are permitted to teach or manage. */
class RankingAccessService {

	/** Receives authoritative group ownership and active subject definitions. */
	public function __construct( private readonly GroupsRepository $groups, private readonly SubjectRepository $subjects ) {}

	/** Capability actually authorizing this request; parent/learner roles never qualify. */
	public function capabilityFor( int $userId ): ?Capability {
		foreach ( array( Capability::Admin, Capability::ManageLmsPlatform, Capability::ManageLmsTeaching ) as $capability ) {
			if ( user_can( $userId, $capability->value ) ) {
				return $capability;
			}
		}
		return null;
	}

	/** Active directions, restricted to the teacher's own groups unless they manage the platform. */
	public function subjectsFor( int $userId ): array {
		$capability = $this->capabilityFor( $userId );
		if ( null === $capability ) {
			return array();
		}
		$owned = null;
		if ( Capability::ManageLmsTeaching === $capability ) {
			$owned = array_fill_keys( array_map( static fn( object $group ): string => (string) $group->subject_key, $this->groups->findByTeacherId( $userId ) ), true );
		}
		$result = array();
		foreach ( $this->subjects->readActive() as $subject ) {
			if ( null === $owned || isset( $owned[ $subject->key ] ) ) {
				$result[] = array(
					'key'  => (string) $subject->key,
					'name' => (string) $subject->name,
				);
			}
		}
		return $result;
	}
}
