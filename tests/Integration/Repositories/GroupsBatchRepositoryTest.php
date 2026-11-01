<?php

declare( strict_types=1 );

namespace Integration\Repositories;

use Inc\Repositories\WPDBRepositories\GroupsRepository;
use PHPUnit\Framework\TestCase;

class GroupsBatchRepositoryTest extends TestCase {
	public function test_requested_groups_are_keyed_and_queries_are_bounded(): void {
		$db = new \FakeWpdb();
		$db->queueResults( array( (object) array( 'id' => 1, 'subject_key' => 'inf' ) ) );
		$db->queueResults( array( (object) array( 'id' => 501, 'subject_key' => 'math' ) ) );
		$repo = new GroupsRepository( $db );
		self::assertSame( array( 1, 501 ), array_keys( $repo->findByIds( range( 1, 501 ) ) ) );
		self::assertCount( 2, $db->queries );
		self::assertStringContainsString( 'WHERE id IN (501)', $db->lastQuery() );
	}

	public function test_empty_group_set_never_reads_all_groups(): void {
		$db = new \FakeWpdb();
		self::assertSame( array(), ( new GroupsRepository( $db ) )->findByIds( array() ) );
		self::assertCount( 0, $db->queries );
	}
}
