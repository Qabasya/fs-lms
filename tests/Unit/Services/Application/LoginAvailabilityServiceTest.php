<?php

declare( strict_types=1 );

namespace Unit\Services\Application;

use Inc\Managers\Person\UserManager;
use Inc\Repositories\WPDBRepositories\ApplicationRepository;
use Inc\Services\Application\LoginAvailabilityService;
use Inc\Services\Security\PiiCryptoService;
use PHPUnit\Framework\TestCase;

/**
 * Логин занят учёткой WordPress или другой незавершённой заявкой.
 */
class LoginAvailabilityServiceTest extends TestCase {

	public function test_login_of_wordpress_user_is_taken(): void {
		$users = $this->createStub( UserManager::class );
		$users->method( 'findByLogin' )->willReturn( new \WP_User() );

		$service = new LoginAvailabilityService( $users, $this->createStub( ApplicationRepository::class ), new PiiCryptoService() );

		$this->assertTrue( $service->isTaken( 'ivan' ) );
	}

	public function test_login_of_pending_application_is_taken_by_normalized_hash(): void {
		$crypto = new PiiCryptoService();
		$apps   = $this->createMock( ApplicationRepository::class );
		$apps->expects( $this->once() )
			->method( 'existsActiveByUsernameHash' )
			->with( $crypto->hash( 'ivan' ), 7 )
			->willReturn( true );

		$service = new LoginAvailabilityService( $this->createStub( UserManager::class ), $apps, $crypto );

		// Регистр и пробелы по краям логинов WordPress не различает.
		$this->assertTrue( $service->isTaken( ' IVAN ', 7 ) );
	}

	public function test_empty_login_is_never_taken(): void {
		$apps = $this->createMock( ApplicationRepository::class );
		$apps->expects( $this->never() )->method( 'existsActiveByUsernameHash' );

		$service = new LoginAvailabilityService( $this->createStub( UserManager::class ), $apps, new PiiCryptoService() );

		$this->assertFalse( $service->isTaken( '  ' ) );
	}
}
