<?php

declare( strict_types=1 );

namespace Unit\Callbacks\Profile;

use Inc\Callbacks\Profile\LearnerCallbacks;
use Inc\DTO\Profile\ProfileContext;
use Inc\Enums\Access\UserRole;
use Inc\DTO\Profile\LearnerDashboardDTO;
use Inc\Services\Profile\HomeLayoutService;
use Inc\Services\Profile\LearnerService;
use Inc\Services\Profile\ProfileViewResolver;
use PHPUnit\Framework\TestCase;

class LearnerCallbacksTest extends TestCase {

	private LearnerService&\PHPUnit\Framework\MockObject\MockObject $service;
	private ProfileViewResolver&\PHPUnit\Framework\MockObject\MockObject $resolver;
	private HomeLayoutService&\PHPUnit\Framework\MockObject\MockObject $layouts;
	private LearnerCallbacks $cb;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_ajax();
		$GLOBALS['_test_logged_in'] = true;
		$this->service  = $this->createMock( LearnerService::class );
		$this->resolver = $this->createMock( ProfileViewResolver::class );
		$this->layouts  = $this->createMock( HomeLayoutService::class );
		$this->layouts->method( 'get' )->willReturn( array( 'sections' => array( 'tiles', 'schedule', 'deadlines' ), 'tiles' => null ) );
		$this->cb       = new LearnerCallbacks( $this->service, $this->resolver, $this->layouts );
	}

	public function test_student_sees_only_self_ignoring_param(): void {
		$this->resolver->method( 'context' )->willReturn(
			new ProfileContext( 1, 5, UserRole::FSStudent, 5, false, array() )
		);
		$this->service->expects( $this->once() )->method( 'build' )->with( 5 )->willReturn( new LearnerDashboardDTO( null, array(), array(), array(), array(), array(), array(), array(), array() ) );
		$_POST = array( 'student_person_id' => '777' ); // попытка подмены игнорируется

		self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxGetLearnerProfile() )->success );
	}

	public function test_parent_can_view_own_child(): void {
		$this->resolver->method( 'context' )->willReturn(
			new ProfileContext( 1, 3, UserRole::FSParent, 7, true, array( array( 'personId' => 7, 'name' => 'A' ), array( 'personId' => 8, 'name' => 'B' ) ) )
		);
		$this->service->expects( $this->once() )->method( 'build' )->with( 8 )->willReturn( new LearnerDashboardDTO( null, array(), array(), array(), array(), array(), array(), array(), array() ) );
		$_POST = array( 'student_person_id' => '8' );

		self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxGetLearnerProfile() )->success );
	}

	public function test_parent_foreign_child_falls_back_to_default(): void {
		$this->resolver->method( 'context' )->willReturn(
			new ProfileContext( 1, 3, UserRole::FSParent, 7, true, array( array( 'personId' => 7, 'name' => 'A' ) ) )
		);
		$this->service->expects( $this->once() )->method( 'build' )->with( 7 )->willReturn( new LearnerDashboardDTO( null, array(), array(), array(), array(), array(), array(), array(), array() ) );
		$_POST = array( 'student_person_id' => '999' ); // не свой ребёнок → дефолт

		self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxGetLearnerProfile() )->success );
	}

	/** Раскладка главной — настройка смотрящего (родитель настраивает свою главную, а не ребёнка): уходит в ответ профиля. */
	public function test_profile_response_carries_the_viewers_home_layout(): void {
		$this->resolver->method( 'context' )->willReturn( new ProfileContext( 1, 5, UserRole::FSStudent, 5, false, array() ) );
		$this->service->method( 'build' )->willReturn( new LearnerDashboardDTO( null, array(), array(), array(), array(), array(), array(), array(), array() ) );
		$_POST = array();

		$r = fs_test_capture_json( fn() => $this->cb->ajaxGetLearnerProfile() );

		self::assertSame( array( 'tiles', 'schedule', 'deadlines' ), $r->payload['home_layout']['sections'] );
	}

	public function test_save_home_layout_stores_normalized_layout_for_current_user(): void {
		$GLOBALS['_fs_test_user_id'] = 12;
		$this->resolver->method( 'context' )->willReturn( new ProfileContext( 12, 5, UserRole::FSStudent, 5, false, array() ) );
		$layout = array( 'sections' => array( 'deadlines', 'tiles', 'schedule' ), 'tiles' => array( 'streak' ) );
		$this->layouts->expects( $this->once() )->method( 'save' )->with( 12, $layout )->willReturn( $layout );
		$_POST = array( 'layout' => wp_json_encode( $layout ) );

		try {
			$r = fs_test_capture_json( fn() => $this->cb->ajaxSaveHomeLayout() );
		} finally {
			unset( $GLOBALS['_fs_test_user_id'] );
		}

		self::assertTrue( $r->success );
		self::assertSame( $layout, $r->payload['layout'] );
	}

	public function test_save_home_layout_requires_login_and_valid_json(): void {
		$this->layouts->expects( $this->never() )->method( 'save' );

		$GLOBALS['_test_logged_in'] = false;
		$_POST = array( 'layout' => '{}' );
		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxSaveHomeLayout() )->success );

		$GLOBALS['_test_logged_in'] = true;
		$_POST = array( 'layout' => 'не json' );
		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxSaveHomeLayout() )->success );
	}
}
