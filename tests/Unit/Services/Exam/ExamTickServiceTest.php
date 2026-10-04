<?php

declare( strict_types=1 );

namespace Tests\Unit\Services\Exam;

use Inc\DTO\Exam\ExamRegistrationDTO;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Repositories\WPDBRepositories\ExamRegistrationRepository;
use Inc\Services\Exam\ExamAttemptService;
use Inc\Services\Exam\ExamHoldService;
use Inc\Services\Exam\ExamNoShowService;
use Inc\Services\Exam\ExamTickService;
use Inc\Services\Exam\ExamTime;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ExamTickServiceTest extends TestCase {

	private ExamAttemptService&MockObject $attemptService;
	private ExamNoShowService&MockObject $noShow;
	private AssessmentAttemptRepository&MockObject $attempts;
	private ExamRegistrationRepository&MockObject $registrations;
	private ExamHoldService&MockObject $holds;
	private ExamTickService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->attemptService = $this->createMock( ExamAttemptService::class );
		$this->noShow         = $this->createMock( ExamNoShowService::class );
		$this->attempts       = $this->createMock( AssessmentAttemptRepository::class );
		$this->registrations  = $this->createMock( ExamRegistrationRepository::class );
		$this->holds          = $this->createMock( ExamHoldService::class );

		$time = $this->createStub( ExamTime::class );
		$time->method( 'nowLocal' )->willReturn( '2026-03-12 14:00:00' );
		$time->method( 'nowUtc' )->willReturn( '2026-03-12 11:00:00' );

		$this->service = new ExamTickService( $this->attemptService, $this->noShow, $this->attempts, $this->registrations, $time, $this->holds );
	}

	private function registration( int $id ): ExamRegistrationDTO {
		return ExamRegistrationDTO::fromArray( array(
			'id' => $id, 'participation_id' => 7, 'session_id' => 100, 'status' => 'confirmed', 'active_slot' => 1, 'created_at' => '2026-03-01 00:00:00',
		) );
	}

	public function test_release_holds_delegates_to_hold_service_with_limit(): void {
		$this->holds->expects( self::once() )->method( 'releaseExpired' )->with( 50 )->willReturn( 3 );

		self::assertSame( 3, $this->service->releaseHolds( 50 ) );
	}

	public function test_auto_expire_selects_overdue_attempts_by_local_time(): void {
		$this->attempts->expects( self::once() )->method( 'listOverdueExamIds' )->with( '2026-03-12 14:00:00', 200 )->willReturn( array() );

		self::assertSame( 0, $this->service->autoExpire() );
	}

	public function test_auto_expire_continues_after_single_failure(): void {
		$this->attempts->method( 'listOverdueExamIds' )->willReturn( array( 1, 2, 3 ) );
		$this->attemptService->method( 'finalizeExpired' )->willReturnCallback( static function ( int $id ): bool {
			if ( 2 === $id ) {
				throw new \RuntimeException( 'сбой на одной попытке' );
			}
			return true;
		} );

		self::assertSame( 2, $this->service->autoExpire(), 'Ошибка попытки 2 не останавливает остальные.' );
	}

	public function test_auto_expire_does_not_count_attempts_already_finished(): void {
		$this->attempts->method( 'listOverdueExamIds' )->willReturn( array( 1, 2 ) );
		$this->attemptService->method( 'finalizeExpired' )->willReturnOnConsecutiveCalls( true, false );

		self::assertSame( 1, $this->service->autoExpire() );
	}

	public function test_sweep_marks_missed_and_continues_after_failure(): void {
		$this->registrations->expects( self::once() )->method( 'listActiveOfEndedSessions' )->with( '2026-03-12 11:00:00', 200 )
			->willReturn( array( $this->registration( 10 ), $this->registration( 11 ), $this->registration( 12 ) ) );
		$this->noShow->method( 'markMissed' )->willReturnCallback( static function ( int $id ): bool {
			if ( 11 === $id ) {
				throw new \RuntimeException( 'сбой на одной записи' );
			}
			return true;
		} );

		self::assertSame( 2, $this->service->sweep() );
	}

	public function test_minute_tick_runs_auto_expire_before_no_show_sweep(): void {
		$order = array();
		$this->attempts->method( 'listOverdueExamIds' )->willReturnCallback( static function () use ( &$order ): array {
			$order[] = 'expire';
			return array();
		} );
		$this->registrations->method( 'listActiveOfEndedSessions' )->willReturnCallback( static function () use ( &$order ): array {
			$order[] = 'sweep';
			return array();
		} );

		$result = $this->service->autoExpireTick();

		self::assertSame( array( 'expire', 'sweep' ), $order );
		self::assertSame( array( 'expired' => 0, 'missed' => 0 ), $result );
	}
}
