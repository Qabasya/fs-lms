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

	public function test_ip_blocks_after_ten_failures_and_escalates(): void {
		$ip = '203.0.113.5';
		$key = 'fs_lms_login_abuse_' . hash_hmac( 'sha256', 'ip:' . $ip, FS_LMS_HASH_SALT );
		foreach ( array( 900, 3600, 86400, 86400 ) as $seconds ) {
			for ( $i = 0; $i < 9; ++$i ) { $this->service->recordIpFailure( $ip ); }
			self::assertSame( 0, $this->service->ipRetrySeconds( $ip ) );
			$this->service->recordIpFailure( $ip );
			self::assertEqualsWithDelta( $seconds, $this->service->ipRetrySeconds( $ip ), 1 );
			$before = $GLOBALS['_test_transients'][ $key ];
			$this->service->recordIpFailure( $ip );
			self::assertSame( $before, $GLOBALS['_test_transients'][ $key ] );
			$GLOBALS['_test_transients'][ $key ]['locked_until'] = time() - 1;
			$GLOBALS['_test_transients'][ $key ]['until'] = time() - 1;
		}
		$this->service->clearIpFailures( $ip );
		for ( $i = 0; $i < 10; ++$i ) { $this->service->recordIpFailure( $ip ); }
		self::assertEqualsWithDelta( 900, $this->service->ipRetrySeconds( $ip ), 1 );
		self::assertTrue( $this->service->unblockIpWithStatus( $ip ) );
		self::assertSame( 0, $this->service->ipRetrySeconds( $ip ) );
	}

	public function test_third_login_switch_locks_ip_before_ten_failures(): void {
		$ip = '203.0.113.99';
		foreach ( array( 'one', 'two', 'one' ) as $login ) {
			$this->service->recordIpFailure( $ip, $login );
		}
		self::assertSame( 0, $this->service->ipRetrySeconds( $ip ) );
		$this->service->recordIpFailure( $ip, 'two' );
		self::assertGreaterThan( 0, $this->service->ipRetrySeconds( $ip ) );
		self::assertSame( 'login_switches', $this->service->ipDiagnostics( $ip )['ip_lock_trigger'] );
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
		for ( $i = 0; $i < 10; ++$i ) { $this->service->recordIpFailure( '2001:0db8::1' ); }
		self::assertGreaterThan( 0, $this->service->ipRetrySeconds( '2001:db8::1' ) );
		self::assertFalse( $this->service->unblockIp( 'not-an-ip' ) );
		self::assertNull( $this->service->unblockIpWithStatus( 'not-an-ip' ) );
	}

	public function test_unblocking_ip_does_not_unblock_the_account(): void {
		$ip = '203.0.113.9';
		for ( $i = 0; $i < 10; ++$i ) { $this->service->recordIpFailure( $ip ); }
		for ( $i = 0; $i < 3; ++$i ) { $this->service->recordUserFailure( 17 ); }
		self::assertTrue( $this->service->unblockIpWithStatus( $ip ) );
		self::assertFalse( $this->service->ipBlocked( $ip ) );
		self::assertTrue( $this->service->userBlocked( 17 ) );
		$this->service->unblockUser( 17 );
		self::assertFalse( $this->service->userBlocked( 17 ) );
	}

	public function test_expired_window_starts_a_new_counter(): void {
		$ip = '203.0.113.7';
		for ( $i = 0; $i < 9; ++$i ) { $this->service->recordIpFailure( $ip ); }
		$key = 'fs_lms_login_abuse_' . hash_hmac( 'sha256', 'ip:' . $ip, FS_LMS_HASH_SALT );
		$GLOBALS['_test_transients'][ $key ]['until'] = time() - 1;
		$this->service->recordIpFailure( $ip );
		self::assertFalse( $this->service->ipBlocked( $ip ) );
		for ( $i = 0; $i < 9; ++$i ) { $this->service->recordIpFailure( $ip ); }
		self::assertGreaterThan( 0, $this->service->ipRetrySeconds( $ip ) );
	}

	public function test_lock_failure_cannot_silently_skip_counter_update(): void {
		$locks = $this->createStub( ExamLockRepository::class );
		$locks->method( 'acquire' )->willReturn( false );
		$service = new LoginBlockService( new LoginBlockRepository( $locks ) );
		$this->expectException( \RuntimeException::class );
		$service->recordIpFailure( '203.0.113.8' );
	}
}
