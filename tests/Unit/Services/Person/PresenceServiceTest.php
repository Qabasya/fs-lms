<?php

declare( strict_types=1 );

namespace Unit\Services\Person;

use Inc\Contracts\ClockInterface;
use Inc\Enums\Access\UserRole;
use Inc\Managers\Person\UserManager;
use Inc\Services\Person\PresenceService;
use PHPUnit\Framework\TestCase;

class PresenceServiceTest extends TestCase {

	private UserManager&\PHPUnit\Framework\MockObject\MockObject $users;
	private PresenceService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->users = $this->createMock( UserManager::class );
		$clock       = $this->createMock( ClockInterface::class );
		$clock->method( 'now' )->willReturn( '2026-05-20 12:00:00' );

		$this->service = new PresenceService( $this->users, $clock );
	}

	private function user( string ...$roles ): \WP_User {
		$user        = new \WP_User();
		$user->ID    = 55;
		$user->roles = $roles;
		return $user;
	}

	public function test_login_of_teacher_is_recorded(): void {
		$this->users->method( 'find' )->willReturn( $this->user( UserRole::FSTeacher->value ) );
		$this->users->method( 'getLastSeenAt' )->willReturn( null );
		$this->users->expects( self::once() )->method( 'setLastSeenAt' )->with( 55, '2026-05-20 12:00:00' );

		$this->service->onLogin( 'teacher', $this->user( UserRole::FSTeacher->value ) );
	}

	/** Не чаще раза в 5 минут — запрос на каждый хит был бы лишней записью в БД. */
	public function test_recent_presence_is_not_rewritten(): void {
		$this->users->method( 'find' )->willReturn( $this->user( UserRole::FSTeacher->value ) );
		$this->users->method( 'getLastSeenAt' )->willReturn( '2026-05-20 11:57:00' );
		$this->users->expects( self::never() )->method( 'setLastSeenAt' );

		$this->service->onLogin( 'teacher', $this->user( UserRole::FSTeacher->value ) );
	}

	public function test_non_teacher_is_not_tracked(): void {
		$this->users->method( 'find' )->willReturn( $this->user( UserRole::FSStudent->value ) );
		$this->users->expects( self::never() )->method( 'setLastSeenAt' );

		$this->service->onLogin( 'student', $this->user( UserRole::FSStudent->value ) );
	}
}
