<?php

declare( strict_types=1 );

namespace Unit\Managers\Person;

use Inc\Managers\Person\UserBehaviorManager;
use Inc\Managers\Person\UserManager;
use PHPUnit\Framework\TestCase;

/**
 * hideAdminBarForFrontCabinet() — верхняя админ-панель WP скрыта фронт-кабинетным
 * ролям (преподаватель/ученик/родитель), офисные роли и администратор её сохраняют.
 */
class UserBehaviorManagerTest extends TestCase {

	private UserBehaviorManager $manager;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_test_logged_in']    = true;
		$GLOBALS['_fs_test_can']       = false;
		$GLOBALS['_fs_test_user_roles'] = [];
		$this->manager                  = new UserBehaviorManager( new UserManager() );
	}

	public function test_hides_bar_for_student(): void {
		$GLOBALS['_fs_test_user_roles'] = [ 'lms_student' ];

		self::assertFalse( $this->manager->hideAdminBarForFrontCabinet( true ) );
	}

	public function test_hides_bar_for_parent(): void {
		$GLOBALS['_fs_test_user_roles'] = [ 'lms_parent' ];

		self::assertFalse( $this->manager->hideAdminBarForFrontCabinet( true ) );
	}

	public function test_hides_bar_for_teacher(): void {
		$GLOBALS['_fs_test_user_roles'] = [ 'lms_teacher' ];

		self::assertFalse( $this->manager->hideAdminBarForFrontCabinet( true ) );
	}

	public function test_keeps_bar_for_office_role(): void {
		$GLOBALS['_fs_test_user_roles'] = [ 'lms_office' ];

		self::assertTrue( $this->manager->hideAdminBarForFrontCabinet( true ) );
	}

	public function test_keeps_bar_for_methodist(): void {
		$GLOBALS['_fs_test_user_roles'] = [ 'lms_methodist' ];

		self::assertTrue( $this->manager->hideAdminBarForFrontCabinet( true ) );
	}

	/** Дуал-роль admin+FSTeacher — Capability::Admin перебивает денилист, панель остаётся. */
	public function test_keeps_bar_for_admin_even_with_front_cabinet_role(): void {
		$GLOBALS['_fs_test_can']        = true;
		$GLOBALS['_fs_test_user_roles'] = [ 'lms_teacher' ];

		self::assertTrue( $this->manager->hideAdminBarForFrontCabinet( true ) );
	}

	public function test_keeps_default_for_logged_out_visitor(): void {
		$GLOBALS['_test_logged_in'] = false;

		self::assertTrue( $this->manager->hideAdminBarForFrontCabinet( true ) );
	}
}
