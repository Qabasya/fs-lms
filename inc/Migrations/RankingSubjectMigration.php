<?php
/** Migrate legacy ranking configuration to subject policies. */

declare( strict_types=1 );

namespace Inc\Migrations;

use Inc\Repositories\OptionsRepositories\PluginConfigRepository;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Services\Shared\PluginConfig;

/** Copies the effective legacy policy into each existing subject once. */
class RankingSubjectMigration {
	/** Receives existing configuration and subject storage. */
	public function __construct(
		private readonly PluginConfigRepository $repository,
		private readonly SubjectRepository $subjects,
		private readonly PluginConfig $config,
	) {}

	/** Apply once, retaining any subject entries already present. */
	public function run(): void {
		$stored = $this->repository->get();
		if ( (int) $stored['ranking_subjects_version'] >= 2 ) {
			return;
		}
		$this->repository->mutate( function ( array $current ): array {
			if ( (int) $current['ranking_subjects_version'] >= 2 ) {
				return $current;
			}
			$this->subjects->refreshCache();
			$map = is_array( $current['ranking_subjects'] ) ? $current['ranking_subjects'] : array();
			foreach ( (int) $current['ranking_subjects_version'] < 1 ? $this->subjects->readAll() : array() as $subject ) {
				if ( ! array_key_exists( $subject->key, $map ) ) {
					$policy = $this->config->rankingForSubject( $subject->key );
					foreach ( array( 'practice', 'independent', 'homework', 'survey', 'assessment', 'attendance_bonus' ) as $field ) {
						$value = $current[ 'ranking_' . $field ] ?? null;
						if ( is_numeric( $value ) && is_finite( (float) $value ) && (float) $value >= 0 && (float) $value <= 1000 ) {
							$policy[ $field ] = (float) $value;
						}
					}
					$map[ $subject->key ] = $policy;
				}
				if ( is_array( $map[ $subject->key ] ) && empty( $map[ $subject->key ]['generation'] ) ) {
					$map[ $subject->key ]['generation'] = bin2hex( random_bytes( 16 ) );
				}
			}
			$excluded = is_array( $current['ranking_excluded_students'] ?? null ) ? $current['ranking_excluded_students'] : array();
			foreach ( $map as &$policy ) {
				if ( is_array( $policy ) ) {
					$excluded = array_merge( $excluded, is_array( $policy['excluded_students'] ?? null ) ? $policy['excluded_students'] : array() );
					unset( $policy['excluded_students'] );
				}
			}
			unset( $policy );
			$current['ranking_excluded_students'] = array_values( array_unique( array_filter( array_map( 'intval', $excluded ), static fn( int $id ): bool => $id > 0 ) ) );
			$current['ranking_subjects'] = $map;
			$current['ranking_subjects_version'] = 2;
			return $current;
		} );
	}
}
