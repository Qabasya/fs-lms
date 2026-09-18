<?php

declare( strict_types=1 );

namespace Unit\Enums;

use Inc\Enums\Access\Capability;
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

	public function test_primary_for_cabinet_no_roles_falls_back_to_fs_student(): void {
		self::assertSame( UserRole::FSStudent, UserRole::primaryForCabinet( array() ) );
	}

	public function test_primary_for_cabinet_unknown_non_admin_role_falls_back_to_fs_student(): void {
		self::assertSame( UserRole::FSStudent, UserRole::primaryForCabinet( array( 'subscriber' ) ) );
	}

	public function test_methodist_manages_subjects_section(): void {
		// Раздел «Предметы» держится на ManageSubjects: меню, таксономии, типовые
		// условия и шаблоны заданий проверяют именно это право.
		self::assertArrayHasKey( Capability::ManageSubjects->value, UserRole::FSMethodist->capabilities() );
	}

	public function test_methodist_stays_out_of_the_rest_of_the_platform(): void {
		// Заявки, ПД и зачисление методисту не открываются: ManageSubjects
		// заведён отдельно от ManageLmsPlatform именно ради этого.
		$caps = UserRole::FSMethodist->capabilities();

		self::assertArrayNotHasKey( Capability::ManageLmsPlatform->value, $caps );
		self::assertArrayNotHasKey( Capability::ViewPII->value, $caps );
		self::assertArrayNotHasKey( Capability::ManageApplications->value, $caps );
	}

	public function test_office_also_manages_subjects_section(): void {
		// Офису вкладку показывали в меню (ManageLmsPlatform), но каждое действие
		// внутри требовало manage_options и отдавало 403.
		self::assertArrayHasKey( Capability::ManageSubjects->value, UserRole::FSOffice->capabilities() );
	}

	public function test_teacher_does_not_manage_subjects_section(): void {
		self::assertArrayNotHasKey( Capability::ManageSubjects->value, UserRole::FSTeacher->capabilities() );
	}
}
