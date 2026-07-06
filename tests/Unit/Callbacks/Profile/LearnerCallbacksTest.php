<?php

declare( strict_types=1 );

namespace Unit\Callbacks\Profile;

use Inc\Callbacks\Profile\LearnerCallbacks;
use Inc\DTO\Profile\ProfileContext;
use Inc\Enums\Access\UserRole;
use Inc\Services\Enrollment\OpenGroupEnrollmentService;
use Inc\Services\Profile\LearnerService;
use Inc\Services\Profile\ProfileViewResolver;
use PHPUnit\Framework\TestCase;

class LearnerCallbacksTest extends TestCase {

	private LearnerService&\PHPUnit\Framework\MockObject\MockObject $service;
	private ProfileViewResolver&\PHPUnit\Framework\MockObject\MockObject $resolver;
	private OpenGroupEnrollmentService&\PHPUnit\Framework\MockObject\MockObject $openEnroll;
	private LearnerCallbacks $cb;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_ajax();
		$GLOBALS['_test_logged_in'] = true;
		$this->service    = $this->createMock( LearnerService::class );
		$this->resolver   = $this->createMock( ProfileViewResolver::class );
		$this->openEnroll = $this->createMock( OpenGroupEnrollmentService::class );
		$this->cb         = new LearnerCallbacks( $this->service, $this->resolver, $this->openEnroll );
	}

	public function test_student_sees_only_self_ignoring_param(): void {
		$this->resolver->method( 'context' )->willReturn(
			new ProfileContext( 1, 5, UserRole::FSStudent, 5, false, array() )
		);
		$this->service->expects( $this->once() )->method( 'build' )->with( 5 )->willReturn( array( 'groups' => array() ) );
		$_POST = array( 'student_person_id' => '777' ); // попытка подмены игнорируется

		self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxGetLearnerProfile() )->success );
	}

	public function test_parent_can_view_own_child(): void {
		$this->resolver->method( 'context' )->willReturn(
			new ProfileContext( 1, 3, UserRole::FSParent, 7, true, array( array( 'personId' => 7, 'name' => 'A' ), array( 'personId' => 8, 'name' => 'B' ) ) )
		);
		$this->service->expects( $this->once() )->method( 'build' )->with( 8 )->willReturn( array( 'groups' => array() ) );
		$_POST = array( 'student_person_id' => '8' );

		self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxGetLearnerProfile() )->success );
	}

	public function test_parent_foreign_child_falls_back_to_default(): void {
		$this->resolver->method( 'context' )->willReturn(
			new ProfileContext( 1, 3, UserRole::FSParent, 7, true, array( array( 'personId' => 7, 'name' => 'A' ) ) )
		);
		$this->service->expects( $this->once() )->method( 'build' )->with( 7 )->willReturn( array( 'groups' => array() ) );
		$_POST = array( 'student_person_id' => '999' ); // не свой ребёнок → дефолт

		self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxGetLearnerProfile() )->success );
	}

	// --- Самозапись в открытую группу (Эпик 15, П10) ---

	public function test_self_enroll_enrolls_own_person(): void {
		$this->resolver->method( 'context' )->willReturn(
			new ProfileContext( 1, 5, UserRole::FSStudent, 5, false, array() )
		);
		$this->openEnroll->expects( $this->once() )
			->method( 'enrollMany' )
			->with( array( 5 ), 42, $this->anything() )
			->willReturn( array( 'added' => 1, 'skipped' => 0 ) );
		$_POST = array( 'group_id' => '42' );

		self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxSelfEnrollOpenGroup() )->success );
	}

	public function test_self_enroll_denied_for_parent(): void {
		$this->resolver->method( 'context' )->willReturn(
			new ProfileContext( 1, 3, UserRole::FSParent, 7, true, array( array( 'personId' => 7, 'name' => 'A' ) ) )
		);
		$this->openEnroll->expects( $this->never() )->method( 'enrollMany' );
		$_POST = array( 'group_id' => '42' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxSelfEnrollOpenGroup() )->success );
	}

	public function test_self_enroll_duplicate_returns_error(): void {
		$this->resolver->method( 'context' )->willReturn(
			new ProfileContext( 1, 5, UserRole::FSStudent, 5, false, array() )
		);
		$this->openEnroll->method( 'enrollMany' )->willReturn( array( 'added' => 0, 'skipped' => 1 ) );
		$_POST = array( 'group_id' => '42' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxSelfEnrollOpenGroup() )->success );
	}
}
