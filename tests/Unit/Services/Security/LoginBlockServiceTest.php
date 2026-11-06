<?php

declare(strict_types=1);

namespace Unit\Services\Security;

use Inc\Repositories\OptionsRepositories\LoginBlockRepository;
use Inc\Repositories\WPDBRepositories\ExamLockRepository;
use Inc\Services\Security\LoginBlockService;
use PHPUnit\Framework\TestCase;

class LoginBlockServiceTest extends TestCase {
	private LoginBlockService $service;
	private array $previousOptions;

	protected function setUp(): void {
		$this->previousOptions = $GLOBALS['_test_options'] ?? array();
		$GLOBALS['_test_options'] = array();
		$GLOBALS['_test_transients'] = array();
		$locks = $this->createStub( ExamLockRepository::class );
		$locks->method( 'acquire' )->willReturn( true );
		$this->service = new LoginBlockService( new LoginBlockRepository( $locks ) );
	}

	protected function tearDown(): void {
		$GLOBALS['_test_options'] = $this->previousOptions;
		parent::tearDown();
	}

	public function test_ip_blocks_on_twentieth_failure_and_persists_until_unblocked(): void {
		for ( $i = 0; $i < 19; ++$i ) { $this->service->recordIpFailure( '203.0.113.5' ); }
		self::assertFalse( $this->service->ipBlocked( '203.0.113.5' ) );
		$this->service->recordIpFailure( '203.0.113.5' );
		self::assertTrue( $this->service->ipBlocked( '203.0.113.5' ) );
		$GLOBALS['_test_transients'] = array();
		self::assertTrue( $this->service->ipBlocked( '203.0.113.5' ) );
		self::assertTrue( $this->service->unblockIpWithStatus( '203.0.113.5' ) );
		self::assertFalse( $this->service->ipBlocked( '203.0.113.5' ) );
		self::assertFalse( $this->service->unblockIpWithStatus( '203.0.113.5' ), 'повторное снятие сообщает об отсутствии блока' );
	}

	public function test_user_blocks_after_three_failures_and_success_does_not_clear_a_block(): void {
		for ( $i = 0; $i < 3; ++$i ) { $this->service->recordUserFailure( 17 ); }
		self::assertTrue( $this->service->userBlocked( 17 ) );
		$this->service->clearUserFailures( 17 );
		self::assertTrue( $this->service->userBlocked( 17 ) );
		$this->service->unblockUser( 17 );
		self::assertFalse( $this->service->userBlocked( 17 ) );
	}

	public function test_ip_is_normalized_and_invalid_ip_is_rejected(): void {
		for ( $i = 0; $i < 20; ++$i ) { $this->service->recordIpFailure( '2001:0db8::1' ); }
		self::assertTrue( $this->service->ipBlocked( '2001:db8::1' ) );
		self::assertFalse( $this->service->unblockIp( 'not-an-ip' ) );
		self::assertNull( $this->service->unblockIpWithStatus( 'not-an-ip' ) );
	}

	public function test_unblocking_ip_does_not_unblock_the_account(): void {
		$ip = '203.0.113.9';
		for ( $i = 0; $i < 20; ++$i ) { $this->service->recordIpFailure( $ip ); }
		for ( $i = 0; $i < 3; ++$i ) { $this->service->recordUserFailure( 17 ); }
		self::assertTrue( $this->service->unblockIpWithStatus( $ip ) );
		self::assertFalse( $this->service->ipBlocked( $ip ) );
		self::assertTrue( $this->service->userBlocked( 17 ) );
		$this->service->unblockUser( 17 );
		self::assertFalse( $this->service->userBlocked( 17 ) );
	}

	public function test_expired_window_starts_a_new_counter(): void {
		$ip = '203.0.113.7';
		for ( $i = 0; $i < 19; ++$i ) { $this->service->recordIpFailure( $ip ); }
		$key = 'fs_lms_login_abuse_' . hash_hmac( 'sha256', 'ip:' . $ip, FS_LMS_HASH_SALT );
		$GLOBALS['_test_transients'][ $key ]['until'] = time() - 1;
		$this->service->recordIpFailure( $ip );
		self::assertFalse( $this->service->ipBlocked( $ip ) );
		for ( $i = 0; $i < 19; ++$i ) { $this->service->recordIpFailure( $ip ); }
		self::assertTrue( $this->service->ipBlocked( $ip ) );
	}

	public function test_lock_failure_cannot_silently_skip_counter_update(): void {
		$locks = $this->createStub( ExamLockRepository::class );
		$locks->method( 'acquire' )->willReturn( false );
		$service = new LoginBlockService( new LoginBlockRepository( $locks ) );
		$this->expectException( \RuntimeException::class );
		$service->recordIpFailure( '203.0.113.8' );
	}
}
