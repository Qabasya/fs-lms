<?php

declare( strict_types=1 );

namespace Unit\Enums\Course;

use Inc\Enums\Course\AccessMode;
use Inc\Enums\Course\GroupType;
use PHPUnit\Framework\TestCase;

class GroupTypeTest extends TestCase {
	public function test_every_storage_combination_round_trips_including_legacy(): void {
		foreach ( AccessMode::cases() as $mode ) {
			foreach ( array( false, true ) as $shared ) {
				$type = GroupType::fromStorage( $mode->value, $shared );
				self::assertSame( $mode, $type->accessMode() );
				self::assertSame( $shared, $type->isShared() );
			}
		}
	}

	public function test_only_four_canonical_types_can_be_created(): void {
		self::assertCount( 4, GroupType::selectable() );
		foreach ( GroupType::selectable() as $type ) {
			self::assertFalse( $type->isLegacy() );
		}
		self::assertSame( AccessMode::Scheduled, GroupType::Shared->accessMode() );
		self::assertFalse( GroupType::Individual->isShared() );
	}
}
