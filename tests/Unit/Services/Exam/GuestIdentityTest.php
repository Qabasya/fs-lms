<?php

declare( strict_types=1 );

namespace Tests\Unit\Services\Exam;

use Inc\Services\Exam\GuestIdentity;
use Inc\Services\Security\PiiCryptoService;
use PHPUnit\Framework\TestCase;

class GuestIdentityTest extends TestCase {

	private GuestIdentity $identity;

	protected function setUp(): void {
		parent::setUp();
		$crypto = $this->createMock( PiiCryptoService::class );
		// Хеш без соли, но с тем же приведением регистра, что у настоящего.
		$crypto->method( 'hash' )->willReturnCallback( static fn ( string $v ): string => hash( 'sha256', mb_strtolower( trim( $v ) ) ) );
		$this->identity = new GuestIdentity( $crypto );
	}

	public function test_same_phone_different_names_give_different_identity(): void {
		$a = $this->identity->identityHash( 'Иванов', 'Пётр', '', '+7 (900) 111-22-33' );
		$b = $this->identity->identityHash( 'Иванова', 'Мария', '', '+7 (900) 111-22-33' );

		self::assertNotSame( $a, $b );
		self::assertSame( $this->identity->phoneHash( '+7 (900) 111-22-33' ), $this->identity->phoneHash( '89001112233' ), 'Телефон сравнивается отдельно.' );
	}

	public function test_name_normalization_ignores_case_spaces_and_yo(): void {
		self::assertSame(
			$this->identity->nameHash( 'Пётр', 'Иванов', 'Сергеевич' ),
			$this->identity->nameHash( '  ПЕТР ', 'иванов', '  Сергеевич  ' )
		);
		self::assertSame( 'петр иванов', $this->identity->normalizeName( 'Пётр', "Иванов\t", '' ) );
	}

	public function test_phone_normalization_ignores_format(): void {
		self::assertSame( '79001112233', $this->identity->normalizePhone( '+7 (900) 111-22-33' ) );
		self::assertSame( '79001112233', $this->identity->normalizePhone( '8 900 111 22 33' ) );
		self::assertSame( '123', $this->identity->normalizePhone( '12-3' ) );
	}
}
