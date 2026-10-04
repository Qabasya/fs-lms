<?php

declare( strict_types=1 );

namespace Tests\Unit\Services\Exam;

use Inc\Enums\Exam\ExamOutboxEvent;
use Inc\Repositories\WPDBRepositories\ExamOutboxEventRepository;
use Inc\Services\Exam\ExamOutbox;
use Inc\Services\Exam\ExamTime;
use PHPUnit\Framework\TestCase;

class ExamOutboxTest extends TestCase {

	private function time(): ExamTime {
		$time = $this->createStub( ExamTime::class );
		$time->method( 'nowUtc' )->willReturn( '2026-03-10 09:00:00' );

		return $time;
	}

	public function test_add_writes_uuid_type_and_payload(): void {
		$repo = $this->createMock( ExamOutboxEventRepository::class );
		$repo->expects( self::once() )->method( 'insert' )->with( self::callback( static function ( array $row ): bool {
			return 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $row['event_uuid'] )
				&& 'attempt_started' === $row['type']
				&& 'participation' === $row['aggregate_type']
				&& 7 === $row['aggregate_id']
				&& 3 === $row['aggregate_version']
				&& '{"attempt_id":11}' === $row['payload'];
		} ) )->willReturn( 1 );

		( new ExamOutbox( $repo, $this->time() ) )->add( ExamOutboxEvent::AttemptStarted, 'participation', 7, 3, array( 'attempt_id' => 11 ) );
	}

	public function test_default_available_at_is_now_utc(): void {
		$repo = $this->createMock( ExamOutboxEventRepository::class );
		$repo->expects( self::once() )->method( 'insert' )->with( self::callback( static function ( array $row ): bool {
			return '2026-03-10 09:00:00' === $row['available_at'] && '2026-03-10 09:00:00' === $row['created_at'];
		} ) )->willReturn( 1 );

		( new ExamOutbox( $repo, $this->time() ) )->add( ExamOutboxEvent::AttemptStarted, 'participation', 7, 3, array() );
	}

	public function test_explicit_available_at_is_used(): void {
		$repo = $this->createMock( ExamOutboxEventRepository::class );
		$repo->expects( self::once() )->method( 'insert' )->with( self::callback( static fn( array $row ): bool => '2026-03-11 00:00:00' === $row['available_at'] ) )->willReturn( 1 );

		( new ExamOutbox( $repo, $this->time() ) )->add( ExamOutboxEvent::EntryOpened, 'session', 1, 0, array(), '2026-03-11 00:00:00' );
	}

	/** Не записанное событие обязано откатить транзакцию вызывающего — поэтому исключение, а не тихий пропуск. */
	public function test_failed_insert_throws(): void {
		$repo = $this->createStub( ExamOutboxEventRepository::class );
		$repo->method( 'insert' )->willReturn( 0 );

		$this->expectException( \RuntimeException::class );
		( new ExamOutbox( $repo, $this->time() ) )->add( ExamOutboxEvent::AttemptStarted, 'participation', 7, 3, array() );
	}
}
