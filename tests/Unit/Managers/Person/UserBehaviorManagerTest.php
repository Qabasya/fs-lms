<?php

declare( strict_types=1 );

namespace Unit\Managers\Person;

use Inc\Enums\Access\UserRole;
use Inc\Managers\Person\UserBehaviorManager;
use Inc\Managers\Person\UserManager;
use PHPUnit\Framework\TestCase;

/**
 * hideAdminBarForFrontCabinet() — верхняя админ-панель WP скрыта пользователям, чьи
 * роли ЦЕЛИКОМ фронт-кабинетные (преподаватель/ученик/родитель, без единой офисной
 * роли рядом); офисные роли (FSOffice/FSMethodist) — сами по себе и в дуал-роли
 * с фронт-кабинетной — панель сохраняют.
 *
 * Источник истины — {@see UserRole::isPureFrontCabinet()} по сырому списку ролей,
 * а не резолвленная приоритетная роль ProfileViewResolver (T-fix: дуал-роль
 * методист+преподаватель раньше ловила цикл редиректов wp-admin ↔ /profile/,
 * т.к. решение принималось по одной и той же приоритетной роли в обоих местах —
 * методист выигрывал приоритет и терял admin-доступ ИЛИ витрину в зависимости от
 * того, как резолвилась роль. Теперь решения независимы: /profile/ открыт, если
 * ХОТЬ ОДНА роль пользователя имеет витрину; wp-admin закрыт, только если НИ ОДНА
 * роль не офисная).
 */
class UserBehaviorManagerTest extends TestCase {

	private UserBehaviorManager $manager;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_test_logged_in']     = true;
		$GLOBALS['_fs_test_can']        = false;
		$GLOBALS['_fs_test_userdata']   = array();

		$this->manager = new UserBehaviorManager( new UserManager() );
	}

	private function mockRoles( int $userId, array $roles ): void {
		$GLOBALS['_fs_test_user_id'] = $userId;
		$user           = new \WP_User();
		$user->ID       = $userId;
		$user->roles    = $roles;
		$GLOBALS['_fs_test_userdata'][ $userId ] = $user;
	}

	public function test_hides_bar_for_student(): void {
		$this->mockRoles( 1, array( UserRole::FSStudent->value ) );

		self::assertFalse( $this->manager->hideAdminBarForFrontCabinet( true ) );
	}

	public function test_hides_bar_for_parent(): void {
		$this->mockRoles( 1, array( UserRole::FSParent->value ) );

		self::assertFalse( $this->manager->hideAdminBarForFrontCabinet( true ) );
	}

	public function test_hides_bar_for_teacher(): void {
		$this->mockRoles( 1, array( UserRole::FSTeacher->value ) );

		self::assertFalse( $this->manager->hideAdminBarForFrontCabinet( true ) );
	}

	public function test_keeps_bar_for_office_role(): void {
		$this->mockRoles( 1, array( UserRole::FSOffice->value ) );

		self::assertTrue( $this->manager->hideAdminBarForFrontCabinet( true ) );
	}

	public function test_keeps_bar_for_methodist(): void {
		$this->mockRoles( 1, array( UserRole::FSMethodist->value ) );

		self::assertTrue( $this->manager->hideAdminBarForFrontCabinet( true ) );
	}

	/**
	 * Регрессия: дуал-роль методист+преподаватель сохраняет и панель, и полный
	 * доступ в wp-admin (методисту он нужен для авторинга) — при этом /profile/
	 * для неё всё равно открыт ({@see \Inc\Services\Profile\ProfileViewResolver}),
	 * так что пользователь не запирается ни в одном из двух мест.
	 */
	public function test_keeps_bar_for_methodist_plus_teacher_dual_role(): void {
		$this->mockRoles( 1, array( UserRole::FSMethodist->value, UserRole::FSTeacher->value ) );

		self::assertTrue( $this->manager->hideAdminBarForFrontCabinet( true ) );
	}

	/** Дуал-роль admin+FSTeacher — Capability::Admin перебивает денилист, панель остаётся. */
	public function test_keeps_bar_for_admin_even_with_front_cabinet_role(): void {
		$GLOBALS['_fs_test_can'] = true;
		$this->mockRoles( 1, array( UserRole::FSTeacher->value ) );

		self::assertTrue( $this->manager->hideAdminBarForFrontCabinet( true ) );
	}

	public function test_keeps_default_for_logged_out_visitor(): void {
		$GLOBALS['_test_logged_in'] = false;

		self::assertTrue( $this->manager->hideAdminBarForFrontCabinet( true ) );
	}
}
