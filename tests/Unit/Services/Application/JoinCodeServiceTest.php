<?php

declare( strict_types=1 );

namespace Unit\Services\Application;

use Inc\Services\Application\JoinCodeService;
use Inc\Services\Security\PiiCryptoService;
use PHPUnit\Framework\TestCase;

/**
 * Срок жизни выданной родителю JOIN-ссылки: 72 часа в UTC.
 */
class JoinCodeServiceTest extends TestCase {

	private function service(): JoinCodeService {
		return new JoinCodeService( new PiiCryptoService() );
	}

	public function test_expires_at_is_72_hours_ahead_in_utc(): void {
		$expected = gmdate( 'Y-m-d H:i:s', time() + JoinCodeService::TTL_HOURS * HOUR_IN_SECONDS );

		// Допуск в секунду: между двумя вызовами time() может смениться секунда.
		$this->assertLessThanOrEqual( 1, abs( strtotime( $this->service()->expiresAt() ) - strtotime( $expected ) ) );
	}

	public function test_link_without_expiry_never_expires(): void {
		$this->assertFalse( $this->service()->isExpired( null ) );
		$this->assertFalse( $this->service()->isExpired( '' ) );
	}

	public function test_past_date_is_expired_and_future_is_not(): void {
		$service = $this->service();

		$this->assertTrue( $service->isExpired( gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ) );
		$this->assertFalse( $service->isExpired( gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ) ) );
	}
}
