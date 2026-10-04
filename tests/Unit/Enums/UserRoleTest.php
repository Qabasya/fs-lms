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
	public function test_teacher_and_methodist_have_exam_caps(): void {
		foreach ( array( UserRole::FSTeacher, UserRole::FSMethodist ) as $role ) {
			$caps = $role->capabilities();
			self::assertArrayHasKey( Capability::ManageExams->value, $caps, $role->value );
			self::assertArrayHasKey( Capability::ManageExamGuests->value, $caps, $role->value );
			self::assertArrayHasKey( Capability::ShareExamResults->value, $caps, $role->value );
		}
	}

	public function test_office_has_no_exam_management_caps(): void {
		$caps = UserRole::FSOffice->capabilities();

		self::assertArrayNotHasKey( Capability::ManageExams->value, $caps );
		self::assertArrayNotHasKey( Capability::ManageExamGuests->value, $caps );
		self::assertArrayNotHasKey( Capability::ShareExamResults->value, $caps );
	}

	public function test_student_and_parent_have_no_exam_caps(): void {
		foreach ( array( UserRole::FSStudent, UserRole::FSParent ) as $role ) {
			foreach ( array( Capability::ManageExams, Capability::ManageExamGuests, Capability::ShareExamResults, Capability::ResolveExamPayments ) as $cap ) {
				self::assertArrayNotHasKey( $cap->value, $role->capabilities(), $role->value . ' / ' . $cap->value );
			}
		}
	}

	public function test_office_has_only_payment_resolution_among_exam_caps(): void {
		$caps = UserRole::FSOffice->capabilities();

		self::assertArrayHasKey( Capability::ResolveExamPayments->value, $caps );
		self::assertArrayNotHasKey( Capability::ManageExams->value, $caps );
	}

	public function test_teacher_has_no_payment_resolution(): void {
		self::assertArrayNotHasKey( Capability::ResolveExamPayments->value, UserRole::FSTeacher->capabilities() );
		self::assertArrayNotHasKey( Capability::ResolveExamPayments->value, UserRole::FSMethodist->capabilities() );
	}
}
