<?php

declare( strict_types=1 );

namespace Unit\Callbacks\Enrollment;

use Inc\Callbacks\Enrollment\UserCredentialsCallbacks;
use Inc\Contracts\LogEventDispatcherInterface;
use Inc\Enums\Auth\AuthAction;
use Inc\Enums\Auth\AuthResult;
use Inc\Managers\Person\UserManager;
use Inc\Services\Log\AuthLogWriter;
use Inc\Services\Security\LoginGuardService;
use Inc\Services\Security\PasswordGeneratorService;
use PHPUnit\Framework\TestCase;
use WP_User;

/** Снятие блокировки входа администратором из карточки персоны. */
class UserCredentialsCallbacksTest extends TestCase {

	private LoginGuardService&\PHPUnit\Framework\MockObject\MockObject $loginGuard;
	private AuthLogWriter&\PHPUnit\Framework\MockObject\MockObject $authLog;
	private UserManager&\PHPUnit\Framework\MockObject\MockObject $users;
	private UserCredentialsCallbacks $cb;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_ajax();
		$GLOBALS['_fs_test_user_id'] = 1;

		$this->loginGuard = $this->createMock( LoginGuardService::class );
		$this->authLog    = $this->createMock( AuthLogWriter::class );
		$this->users      = $this->createMock( UserManager::class );
		$this->cb         = new UserCredentialsCallbacks(
			$this->createStub( PasswordGeneratorService::class ),
			$this->users,
			$this->createStub( LogEventDispatcherInterface::class ),
			$this->loginGuard,
			$this->authLog
		);
	}

	public function test_unlock_resets_account_and_writes_auth_log(): void {
		$user             = new WP_User();
		$user->ID         = 7;
		$user->user_login = 'ivan';
		$this->users->method( 'find' )->with( 7 )->willReturn( $user );
		$this->loginGuard->expects( $this->once() )->method( 'unlockUser' )->with( 7 );
		$this->authLog->expects( $this->once() )->method( 'recordEvent' )->with(
			AuthAction::LoginUnlocked,
			AuthResult::Success,
			null,
			array( 'note' => 'Снял администратор (ID 1)' ),
			'ivan'
		);
		$_POST = array( 'user_id' => '7' );

		self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxUnlockUserLogin() )->success );
	}

	public function test_unlock_of_unknown_user_changes_nothing(): void {
		$this->users->method( 'find' )->willReturn( null );
		$this->loginGuard->expects( $this->never() )->method( 'unlockUser' );
		$this->authLog->expects( $this->never() )->method( 'recordEvent' );
		$_POST = array( 'user_id' => '999' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxUnlockUserLogin() )->success );
	}

	public function test_unlock_without_capability_is_denied(): void {
		$GLOBALS['_fs_test_can'] = false;
		$this->loginGuard->expects( $this->never() )->method( 'unlockUser' );
		$_POST = array( 'user_id' => '7' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxUnlockUserLogin() )->success );
	}

	public function test_unlock_without_user_id_is_rejected(): void {
		$this->loginGuard->expects( $this->never() )->method( 'unlockUser' );
		$_POST = array();

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxUnlockUserLogin() )->success );
	}
}
