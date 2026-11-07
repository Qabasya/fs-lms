<?php

declare( strict_types=1 );

namespace Tests\Unit\Services\Enrollment;

use Inc\Repositories\OptionsRepositories\AcademicPeriodRepository;
use Inc\Repositories\OptionsRepositories\StudentPeriodMatrixRepository;
use Inc\Repositories\OptionsRepositories\UserRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Services\Enrollment\AcademicPeriodService;
use PHPUnit\Framework\TestCase;

class AcademicPeriodHolidaysTest extends TestCase {
	private function service(): AcademicPeriodService {
		return new AcademicPeriodService( $this->createStub( AcademicPeriodRepository::class ), $this->createStub( UserRepository::class ), $this->createStub( StudentPeriodMatrixRepository::class ), $this->createStub( GroupsRepository::class ) );
	}

	public function test_ranges_are_inclusive_sorted_and_deduplicated(): void {
		self::assertSame( array( '2026-12-31', '2027-01-01', '2027-01-02' ), $this->service()->holidayDates( array(
			array( 'from' => '2027-01-01', 'until' => '2027-01-02' ),
			array( 'from' => '2026-12-31', 'until' => '2027-01-01' ),
		), '2026-09-01', '2027-06-01' ) );
	}

	public function test_invalid_or_outside_range_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->service()->holidayDates( array( array( 'from' => '2026-12-31', 'until' => '2027-01-03' ) ), '2026-09-01', '2027-01-02' );
	}
}
