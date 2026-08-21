<?php

declare( strict_types=1 );

namespace Integration\Repositories;

use FakeWpdb;
use Inc\Repositories\WPDBRepositories\SubstitutionRepository;
use PHPUnit\Framework\TestCase;

class SubstitutionRepositoryTest extends TestCase {

	private FakeWpdb              $wpdb;
	private SubstitutionRepository $repo;

	protected function setUp(): void {
		parent::setUp();
		$this->wpdb = new FakeWpdb();
		$this->repo = new SubstitutionRepository( $this->wpdb );
	}

	public function test_has_upcoming_or_active_grant_ignores_valid_from(): void {
		$this->wpdb->queueVar( 1 );

		$this->repo->hasUpcomingOrActiveGrant( 99, 7 );

		$q = $this->wpdb->lastQuery();
		self::assertStringContainsString( 'substitute_teacher_id = 99', $q );
		self::assertStringContainsString( 'group_id = 7', $q );
		self::assertStringContainsString( 'valid_to >= CURDATE()', $q );
		self::assertStringNotContainsString( 'valid_from', $q );
	}

	public function test_find_upcoming_or_active_by_substitute_filters_only_by_valid_to(): void {
		$this->wpdb->queueResults( [] );

		$this->repo->findUpcomingOrActiveBySubstitute( 99, '2026-08-20' );

		$q = $this->wpdb->lastQuery();
		self::assertStringContainsString( 'substitute_teacher_id = 99', $q );
		self::assertStringContainsString( "valid_to >= '2026-08-20'", $q );
		self::assertStringNotContainsString( 'valid_from <=', $q );
	}

	public function test_find_upcoming_or_active_by_substitute_maps_rows_to_dto(): void {
		$this->wpdb->queueResults( [
			[
				'id'                    => 1,
				'group_id'              => 7,
				'original_teacher_id'   => 42,
				'substitute_teacher_id' => 99,
				'valid_from'            => '2026-09-01',
				'valid_to'              => '2026-09-30',
				'reason'                => null,
				'approved_by'           => 1,
				'created_at'            => '2026-08-20 10:00:00',
			],
		] );

		$rows = $this->repo->findUpcomingOrActiveBySubstitute( 99, '2026-08-20' );

		self::assertCount( 1, $rows );
		self::assertSame( '2026-09-01', $rows[0]->validFrom );
	}
}
