<?php

declare( strict_types=1 );

namespace Integration\Repositories;

use Inc\Enums\Course\GroupType;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use PHPUnit\Framework\TestCase;

class GroupsTypeFilterRepositoryTest extends TestCase {
	public function test_every_type_filter_uses_both_storage_flags(): void {
		foreach ( GroupType::cases() as $type ) {
			$db = new \FakeWpdb();
			( new GroupsRepository( $db ) )->findByFilters( '2026', type: $type );
			self::assertStringContainsString( "access_mode = '" . $type->accessMode()->value . "'", $db->lastQuery() );
			self::assertStringContainsString( 'is_shared = ' . ( $type->isShared() ? '1' : '0' ), $db->lastQuery() );
		}
	}

	public function test_unfiltered_queries_keep_shared_and_scheduleless_groups_visible(): void {
		$db = new \FakeWpdb();
		( new GroupsRepository( $db ) )->findByFilters( '2026' );
		self::assertStringNotContainsString( 'access_mode', $db->lastQuery() );
		self::assertStringNotContainsString( 'is_shared', $db->lastQuery() );
	}
}
