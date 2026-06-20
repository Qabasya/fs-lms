<?php

declare( strict_types=1 );

namespace Unit\Services\Import;

use Inc\Enums\Enrollment\EnrollmentStatus;
use Inc\Services\Import\ExpulsionResolver;
use PHPUnit\Framework\TestCase;

class ExpulsionResolverTest extends TestCase {

	private ExpulsionResolver $resolver;

	protected function setUp(): void {
		parent::setUp();
		$this->resolver = new ExpulsionResolver();
	}

	public function testEmptyReturnsNull(): void {
		$this->assertNull( $this->resolver->resolve( '' ) );
		$this->assertNull( $this->resolver->resolve( '   ' ) );
	}

	public function testEndMapsToFinished(): void {
		$result = $this->resolver->resolve( 'Окончание курса' );

		$this->assertSame( EnrollmentStatus::Finished, $result['status'] );
		$this->assertSame( 'Окончание курса', $result['reason'] );
	}

	public function testTransferMapsToTransferredCaseInsensitive(): void {
		$result = $this->resolver->resolve( '  перевод ' );

		$this->assertSame( EnrollmentStatus::Transferred, $result['status'] );
		$this->assertSame( 'Перевод', $result['reason'] );
	}

	public function testOwnRequestMapsToExpelled(): void {
		$result = $this->resolver->resolve( 'По собственному желанию' );

		$this->assertSame( EnrollmentStatus::Expelled, $result['status'] );
		$this->assertSame( 'По собственному желанию', $result['reason'] );
	}

	public function testFreeTextBecomesOther(): void {
		$result = $this->resolver->resolve( 'Переезд в другой город' );

		$this->assertSame( EnrollmentStatus::Expelled, $result['status'] );
		$this->assertSame( 'Другое: Переезд в другой город', $result['reason'] );
	}

	public function testAlreadyOtherPrefixKeptAsIs(): void {
		$result = $this->resolver->resolve( 'Другое: личные причины' );

		$this->assertSame( EnrollmentStatus::Expelled, $result['status'] );
		$this->assertSame( 'Другое: личные причины', $result['reason'] );
	}
}
