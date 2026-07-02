<?php

declare( strict_types=1 );

namespace Unit\Enums;

use Inc\Enums\Access\UserRole;
use PHPUnit\Framework\TestCase;

class UserRoleTest extends TestCase {

	public function test_primary_for_cabinet_pure_administrator_gets_office(): void {
		self::assertSame( UserRole::FSOffice, UserRole::primaryForCabinet( array( 'administrator' ) ) );
	}

	public function test_primary_for_cabinet_dual_admin_and_teacher_keeps_teacher_priority(): void {
		// Дуал-роль admin+LMS резолвится обычным приоритетом primary(), не FSOffice-суперсетом.
		self::assertSame(
			UserRole::FSTeacher,
			UserRole::primaryForCabinet( array( 'administrator', UserRole::FSTeacher->value ) )
		);
	}

	public function test_primary_for_cabinet_pure_lms_role_unaffected(): void {
		self::assertSame( UserRole::FSStudent, UserRole::primaryForCabinet( array( UserRole::FSStudent->value ) ) );
	}

	public function test_primary_for_cabinet_no_roles_falls_back_to_student(): void {
		self::assertSame( UserRole::Student, UserRole::primaryForCabinet( array() ) );
	}

	public function test_primary_for_cabinet_unknown_non_admin_role_falls_back_to_student(): void {
		self::assertSame( UserRole::Student, UserRole::primaryForCabinet( array( 'subscriber' ) ) );
	}
}
