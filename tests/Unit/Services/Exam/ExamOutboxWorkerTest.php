<?php

declare( strict_types=1 );

namespace Tests\Unit\Services\Exam;

use Inc\DTO\Exam\ExamOutboxEventDTO;
use Inc\Repositories\WPDBRepositories\ExamOutboxEventRepository;
use Inc\Services\Exam\ExamNotificationComposer;
use Inc\Services\Exam\ExamOutboxWorker;
use Inc\Services\Exam\ExamTime;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Worker доставки событий: аренда, отметка обработанных, откладывание при сбое, изоляция строк.
 */
class ExamOutboxWorkerTest extends TestCase {

	private ExamOutboxEventRepository&MockObject $outbox;
	private ExamNotificationComposer&MockObject $composer;
	private ExamOutboxWorker $worker;

	protected function setUp(): void {
		parent::setUp();
		$this->outbox   = $this->createMock( ExamOutboxEventRepository::class );
		$this->composer = $this->createMock( ExamNotificationComposer::class );
		$time           = $this->createStub( ExamTime::class );
		$time->method( 'nowUtc' )->willReturn( '2026-03-12 10:00:00' );
		$time->method( 'addMinutes' )->willReturnCallback( static fn ( string $d, int $m ): string => gmdate( 'Y-m-d H:i:s', strtotime( $d . ' UTC' ) + $m * 60 ) );

		$this->worker = new ExamOutboxWorker( $this->outbox, $this->composer, $time );
	}

	private function row( int $id, int $attempts = 1 ): ExamOutboxEventDTO {
		return ExamOutboxEventDTO::fromArray( array(
			'id' => $id, 'event_uuid' => 'u' . $id, 'type' => 'registration_confirmed', 'aggregate_type' => 'registration', 'aggregate_id' => 5,
			'aggregate_version' => 1, 'payload' => '{}', 'available_at' => '2026-03-12 09:59:00', 'attempts' => $attempts, 'created_at' => '2026-03-12 09:59:00',
		) );
	}

	public function test_leases_for_two_minutes_and_marks_processed(): void {
		$this->outbox->expects( self::once() )->method( 'leaseBatch' )->with( '2026-03-12 10:00:00', '2026-03-12 10:02:00', 100 )->willReturn( array( $this->row( 1 ) ) );
		$this->composer->expects( self::once() )->method( 'handle' );
		$this->outbox->expects( self::once() )->method( 'markProcessed' )->with( 1, '2026-03-12 10:00:00' );

		self::assertSame( 1, $this->worker->run() );
	}

	public function test_processed_row_is_not_handled_twice(): void {
		// Обработанная строка в аренду не попадает: репозиторий отдаёт её один раз, второй запуск получает пустой список.
		$this->outbox->method( 'leaseBatch' )->willReturnOnConsecutiveCalls( array( $this->row( 1 ) ), array() );
		$this->composer->expects( self::once() )->method( 'handle' );

		$this->worker->run();
		$this->worker->run();
	}

	public function test_failed_row_is_rescheduled_with_backoff(): void {
		$this->outbox->method( 'leaseBatch' )->willReturn( array( $this->row( 1, 3 ) ) );
		$this->composer->method( 'handle' )->willThrowException( new \RuntimeException( 'boom' ) );
		$this->outbox->expects( self::never() )->method( 'markProcessed' );
		$this->outbox->expects( self::once() )->method( 'markFailed' )->with( 1, 'boom', '2026-03-12 10:08:00' ); // 2^3 минут

		self::assertSame( 0, $this->worker->run() );
	}

	public function test_backoff_is_capped_at_sixty_minutes(): void {
		$this->outbox->method( 'leaseBatch' )->willReturn( array( $this->row( 1, 9 ) ) );
		$this->composer->method( 'handle' )->willThrowException( new \RuntimeException( 'boom' ) );
		$this->outbox->expects( self::once() )->method( 'markFailed' )->with( 1, 'boom', '2026-03-12 11:00:00' );

		$this->worker->run();
	}

	public function test_failure_of_one_row_does_not_stop_batch(): void {
		$this->outbox->method( 'leaseBatch' )->willReturn( array( $this->row( 1 ), $this->row( 2 ), $this->row( 3 ) ) );
		$this->composer->method( 'handle' )->willReturnCallback( static function ( ExamOutboxEventDTO $r ): void {
			if ( 2 === $r->id ) {
				throw new \RuntimeException( 'boom' );
			}
		} );
		$this->outbox->expects( self::exactly( 2 ) )->method( 'markProcessed' );
		$this->outbox->expects( self::once() )->method( 'markFailed' )->with( 2, self::anything(), self::anything() );

		self::assertSame( 2, $this->worker->run() );
	}
}
