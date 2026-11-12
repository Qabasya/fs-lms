<?php

declare( strict_types=1 );

namespace Unit\Services\Course;

use Inc\Contracts\ClockInterface;
use Inc\DTO\Course\GroupLessonDTO;
use Inc\Enums\Course\RankingAssignmentOrigin;
use Inc\Managers\Course\LessonManager;
use Inc\Repositories\OptionsRepositories\RankingAssignmentSnapshotRepository;
use Inc\Services\Course\RankingAssignmentSnapshotService;
use Inc\Services\Course\RankingAssignmentSwapPlan;
use Inc\Services\Course\RankingTopicSwapService;
use PHPUnit\Framework\TestCase;

class RankingTopicSwapServiceTest extends TestCase {
	public function test_issued_history_and_extra_work_move_to_the_correct_locations(): void {
		$repo = $this->createMock( RankingAssignmentSnapshotRepository::class );
		$repo->method( 'synchronized' )->willReturnCallback( static function ( callable $run ): void { $run(); } );
		$a = array( 7 => array( 'origin' => 'lesson', 'task_points' => array( 1 => 3 ) ), 8 => array( 'origin' => 'extra', 'task_points' => array( 2 => 5 ) ) );
		$b = array( 9 => array( 'origin' => 'lesson', 'task_points' => array( 3 => 7 ) ) );
		$repo->method( 'forLessonFresh' )->willReturnCallback( static fn( int $id ): array => 1 === $id ? $a : $b );
		$repo->expects( self::once() )->method( 'replaceLessons' )->with( array( 1 => array( 8 => $a[8], 9 => $b[9] ), 2 => array( 7 => $a[7] ) ) );
		$service = new RankingTopicSwapService( $repo, new RankingAssignmentSwapPlan(), $this->createStub( RankingAssignmentSnapshotService::class ), $this->createStub( LessonManager::class ), $this->createStub( ClockInterface::class ) );
		$seen = null;
		$service->swap( $this->row( 1, 10, array( 7 ), array( 8 ) ), $this->row( 2, 20, array( 9 ), array() ), array( 7 ), array( 9 ), static function ( array $aExtra, array $bExtra ) use ( &$seen ): void { $seen = array( $aExtra, $bExtra ); } );
		self::assertSame( array( array( 8 ), array() ), $seen );
	}

	public function test_open_legacy_work_is_frozen_with_lesson_date_before_swap(): void {
		$repo = $this->createMock( RankingAssignmentSnapshotRepository::class );
		$repo->method( 'synchronized' )->willReturnCallback( static function ( callable $run ): void { $run(); } );
		$issued = array( 7 => array( 'origin' => 'lesson', 'source' => 'homework', 'issued_at' => '2026-11-30 12:00:00', 'task_points' => array( 3 => 2 ) ) );
		$repo->method( 'forLessonFresh' )->willReturnOnConsecutiveCalls( array(), array(), $issued, array() );
		$assignment = $this->createMock( RankingAssignmentSnapshotService::class );
		$assignment->expects( self::once() )->method( 'capture' )->with( 1, array( 7 ), '2026-11-30 12:00:00', RankingAssignmentOrigin::Lesson );
		$repo->expects( self::once() )->method( 'replaceLessons' )->with( array( 1 => array(), 2 => $issued ) );
		$clock = $this->createStub( ClockInterface::class );
		$clock->method( 'now' )->willReturn( '2026-12-02 10:00:00' );
		$service = new RankingTopicSwapService( $repo, new RankingAssignmentSwapPlan(), $assignment, $this->createStub( LessonManager::class ), $clock );
		$service->swap( $this->row( 1, 10, array( 7 ), array() ), $this->row( 2, 20, array(), array() ), array( 7 ), array(), static function (): void {} );
	}

	public function test_incoming_pending_topic_is_issued_on_swap(): void {
		$repo = $this->createMock( RankingAssignmentSnapshotRepository::class );
		$repo->method( 'synchronized' )->willReturnCallback( static function ( callable $run ): void { $run(); } );
		$pending = array( 9 => array( 'origin' => 'lesson', 'pending' => true, 'issued_at' => '2026-11-30 10:00:00' ) );
		$issued = array( 9 => array( 'origin' => 'lesson', 'source' => 'homework', 'issued_at' => '2026-12-02 10:00:00', 'task_points' => array( 3 => 7 ) ) );
		$repo->method( 'forLessonFresh' )->willReturnOnConsecutiveCalls( array(), $pending, $issued );
		$repo->expects( self::once() )->method( 'replaceLessons' )->with( array( 1 => $issued, 2 => array() ) );
		$assignment = $this->createMock( RankingAssignmentSnapshotService::class );
		$assignment->expects( self::once() )->method( 'capture' )->with( 2, array( 9 ), '2026-12-02 10:00:00', RankingAssignmentOrigin::Lesson );
		$lessons = $this->createStub( LessonManager::class );
		$lessons->method( 'isPublished' )->willReturn( true );
		$clock = $this->createStub( ClockInterface::class );
		$clock->method( 'now' )->willReturn( '2026-12-02 10:00:00' );
		$service = new RankingTopicSwapService( $repo, new RankingAssignmentSwapPlan(), $assignment, $lessons, $clock );
		$service->swap( $this->row( 1, 10, array(), array() ), $this->row( 2, 20, array( 9 ), array() ), array(), array( 9 ), static function (): void {} );
	}

	public function test_overlapping_incoming_work_fails_before_writes(): void {
		$repo = $this->createMock( RankingAssignmentSnapshotRepository::class );
		$repo->method( 'synchronized' )->willReturnCallback( static function ( callable $run ): void { $run(); } );
		$repo->method( 'forLessonFresh' )->willReturnCallback( static fn( int $id ): array => 1 === $id ? array( 9 => array( 'origin' => 'extra' ) ) : array( 9 => array( 'origin' => 'lesson' ) ) );
		$repo->expects( self::never() )->method( 'replaceLessons' );
		$service = new RankingTopicSwapService( $repo, new RankingAssignmentSwapPlan(), $this->createStub( RankingAssignmentSnapshotService::class ), $this->createStub( LessonManager::class ), $this->createStub( ClockInterface::class ) );
		$this->expectException( \DomainException::class );
		$service->swap( $this->row( 1, 10, array(), array( 9 ) ), $this->row( 2, 20, array( 9 ), array() ), array(), array( 9 ), static function (): void { self::fail( 'No data should be moved.' ); } );
	}

	private function row( int $id, int $lessonId, array $snapshot, array $extra ): GroupLessonDTO {
		return new GroupLessonDTO( id: $id, groupId: 1, lessonId: $lessonId, position: 0, workIdsSnapshot: $snapshot, extraWorkIds: $extra, scheduledAt: '2026-11-30 12:00:00', endsAt: null, isPinned: false, teacherUserId: null, visibility: 'open', openedAt: null, homeworkDueAt: null, allowLate: true, recordingUrl: null, createdByUserId: null, updatedByUserId: null );
	}
}
