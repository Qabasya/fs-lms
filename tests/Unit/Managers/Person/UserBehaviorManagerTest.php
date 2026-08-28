<?php

declare( strict_types=1 );

namespace Unit\Managers\Person;

use Inc\DTO\Profile\ProfileContext;
use Inc\Enums\Access\UserRole;
use Inc\Managers\Person\UserBehaviorManager;
use Inc\Managers\Person\UserManager;
use Inc\Services\Profile\ProfileViewResolver;
use PHPUnit\Framework\TestCase;

/**
 * hideAdminBarForFrontCabinet() — верхняя админ-панель WP скрыта фронт-кабинетным
 * ролям (преподаватель/ученик/родитель), офисные роли и администратор её сохраняют.
 *
 * Источник истины «есть ли витрина /profile/» — тот же ProfileViewResolver, что
 * использует ProfileController (T-fix: дуал-роль методист+преподаватель раньше
 * ловила цикл редиректов wp-admin ↔ /profile/, т.к. решение принималось по-разному
 * в двух местах — здесь по сырому списку ролей, там по резолвленной приоритетной).
 */
class UserBehaviorManagerTest extends TestCase {

	private UserBehaviorManager $manager;
	/** @var ProfileViewResolver&\PHPUnit\Framework\MockObject\Stub */
	private ProfileViewResolver $resolver;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_test_logged_in'] = true;
		$GLOBALS['_fs_test_can']    = false;

		$this->resolver = $this->createStub( ProfileViewResolver::class );
		$this->manager   = new UserBehaviorManager( new UserManager(), $this->resolver );
	}

	private function mockRole( UserRole $role, bool $hasView ): void {
		$this->resolver->method( 'context' )->willReturn(
			new ProfileContext( 1, null, $role, null, false )
		);
		$this->resolver->method( 'viewFor' )->willReturn( $hasView ? $this->createStub( \Inc\Contracts\ProfileViewInterface::class ) : null );
	}

	public function test_hides_bar_for_student(): void {
		$this->mockRole( UserRole::FSStudent, true );

		self::assertFalse( $this->manager->hideAdminBarForFrontCabinet( true ) );
	}

	public function test_hides_bar_for_parent(): void {
		$this->mockRole( UserRole::FSParent, true );

		self::assertFalse( $this->manager->hideAdminBarForFrontCabinet( true ) );
	}

	public function test_hides_bar_for_teacher(): void {
		$this->mockRole( UserRole::FSTeacher, true );

		self::assertFalse( $this->manager->hideAdminBarForFrontCabinet( true ) );
	}

	public function test_keeps_bar_for_office_role(): void {
		$this->mockRole( UserRole::FSOffice, false );

		self::assertTrue( $this->manager->hideAdminBarForFrontCabinet( true ) );
	}

	public function test_keeps_bar_for_methodist(): void {
		$this->mockRole( UserRole::FSMethodist, false );

		self::assertTrue( $this->manager->hideAdminBarForFrontCabinet( true ) );
	}

	/**
	 * Регрессия: дуал-роль методист+преподаватель — приоритетная роль (по
	 * {@see UserRole::primary()}) методист, у неё нет витрины → панель остаётся,
	 * как и у чистого методиста. Именно это рассогласование раньше вызывало
	 * цикл редиректов между wp-admin и /profile/ для таких пользователей.
	 */
	public function test_keeps_bar_for_methodist_plus_teacher_dual_role(): void {
		$this->mockRole( UserRole::FSMethodist, false );

		self::assertTrue( $this->manager->hideAdminBarForFrontCabinet( true ) );
	}

	/** Дуал-роль admin+FSTeacher — Capability::Admin перебивает денилист, панель остаётся. */
	public function test_keeps_bar_for_admin_even_with_front_cabinet_role(): void {
		$GLOBALS['_fs_test_can'] = true;
		$this->mockRole( UserRole::FSTeacher, true );

		self::assertTrue( $this->manager->hideAdminBarForFrontCabinet( true ) );
	}

	public function test_keeps_default_for_logged_out_visitor(): void {
		$GLOBALS['_test_logged_in'] = false;

		self::assertTrue( $this->manager->hideAdminBarForFrontCabinet( true ) );
	}
}
