<?php

declare( strict_types=1 );

namespace Unit\Services\Promo;

use Inc\DTO\Promo\CourseOverride;
use Inc\Services\Promo\CourseAssignmentResolver;
use PHPUnit\Framework\TestCase;

class CourseAssignmentResolverTest extends TestCase {

	public function test_inheritance_follows_event_changes_and_missing_assignment(): void {
		$resolver = new CourseAssignmentResolver();
		$override = CourseOverride::inherit();
		self::assertSame( 10, $resolver->resolve( 10, $override ) );
		self::assertSame( 20, $resolver->resolve( 20, $override ) );
		self::assertNull( $resolver->resolve( null, $override ) );
	}

	public function test_individual_assignment_wins_and_revocation_does_not_inherit(): void {
		$resolver = new CourseAssignmentResolver();
		self::assertSame( 30, $resolver->resolve( 10, CourseOverride::assign( 30 ) ) );
		self::assertSame( 30, $resolver->resolve( null, CourseOverride::assign( 30 ) ) );
		self::assertNull( $resolver->resolve( 10, CourseOverride::revoke() ) );
		self::assertNull( $resolver->resolve( 20, CourseOverride::revoke() ) );
	}

	public function test_invalid_individual_course_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		CourseOverride::assign( 0 );
	}

	public function test_invalid_event_course_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		( new CourseAssignmentResolver() )->resolve( -1, CourseOverride::inherit() );
	}
}
