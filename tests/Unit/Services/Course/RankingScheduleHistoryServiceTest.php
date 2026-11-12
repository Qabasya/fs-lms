<?php

declare( strict_types=1 );

namespace Unit\Services\Course;

use Inc\Contracts\ClockInterface;
use Inc\DTO\Course\GroupLessonDTO;
use Inc\Enums\Course\RankingAssignmentOrigin;
use Inc\Managers\Course\LessonManager;
use Inc\Services\Course\RankingAssignmentSnapshotService;
use Inc\Services\Course\RankingScheduleHistoryService;
use PHPUnit\Framework\TestCase;

class RankingScheduleHistoryServiceTest extends TestCase {
	public function test_existing_lesson_uses_scheduled_date_even_when_opened_in_another_period(): void {
		$row = GroupLessonDTO::fromArray( array(
			'id' => 42, 'group_id' => 5, 'lesson_id' => 8, 'position' => 1,
			'visibility' => 'open', 'scheduled_at' => '2026-11-30 10:00:00',
			'opened_at' => '2026-12-01 10:00:00', 'work_ids_snapshot' => '[10]',
		) );
		$snapshots = $this->createMock( RankingAssignmentSnapshotService::class );
		$snapshots->expects( self::once() )->method( 'capture' )->with( 42, array( 10 ), '2026-11-30 10:00:00', RankingAssignmentOrigin::Lesson );
		( new RankingScheduleHistoryService( $this->createStub( LessonManager::class ), $snapshots, $this->createStub( ClockInterface::class ) ) )->captureBeforeMutation( $row );
	}

	public function test_already_open_work_keeps_original_autumn_date_before_schedule_moves_to_winter(): void {
		$row = GroupLessonDTO::fromArray( array(
			'id' => 42, 'group_id' => 5, 'lesson_id' => 8, 'position' => 1,
			'visibility' => 'hidden', 'scheduled_at' => '2026-11-30 10:00:00',
			'work_ids_snapshot' => '[10]', 'extra_work_ids' => '[20]',
		) );
		$clock = $this->createStub( ClockInterface::class );
		$clock->method( 'now' )->willReturn( '2026-12-01 12:00:00' );
		$lessons = $this->createStub( LessonManager::class );
		$lessons->method( 'isPublished' )->willReturn( true );
		$snapshots = $this->createMock( RankingAssignmentSnapshotService::class );
		$calls = array();
		$snapshots->method( 'capture' )->willReturnCallback( static function ( int $id, array $ids, ?string $at, ?RankingAssignmentOrigin $origin ) use ( &$calls ): void { $calls[] = array( $id, $ids, $at, $origin ); } );
		( new RankingScheduleHistoryService( $lessons, $snapshots, $clock ) )->captureBeforeMutation( $row );
		self::assertSame( array(
			array( 42, array( 10 ), '2026-11-30 10:00:00', RankingAssignmentOrigin::Lesson ),
			array( 42, array( 20 ), '2026-11-30 10:00:00', RankingAssignmentOrigin::Extra ),
		), $calls );
	}

	public function test_future_or_unpublished_lesson_is_not_frozen(): void {
		$clock = $this->createStub( ClockInterface::class );
		$clock->method( 'now' )->willReturn( '2026-11-30 10:00:00' );
		$lessons = $this->createStub( LessonManager::class );
		$lessons->method( 'isPublished' )->willReturn( false );
		$snapshots = $this->createMock( RankingAssignmentSnapshotService::class );
		$snapshots->expects( self::never() )->method( 'capture' );
		$snapshots->expects( self::never() )->method( 'markPublicationUnconfirmed' );
		$service = new RankingScheduleHistoryService( $lessons, $snapshots, $clock );
		foreach ( array( '2026-12-01 10:00:00', '2026-11-29 10:00:00' ) as $scheduled ) {
			$service->captureBeforeMutation( GroupLessonDTO::fromArray( array(
				'id' => 42, 'group_id' => 5, 'lesson_id' => 8, 'position' => 1,
				'visibility' => 'hidden', 'scheduled_at' => $scheduled, 'work_ids_snapshot' => '[10]',
			) ) );
		}
	}

	public function test_open_individual_lesson_without_work_snapshot_still_preserves_live_work(): void {
		$row = GroupLessonDTO::fromArray( array(
			'id' => 42, 'group_id' => 5, 'lesson_id' => 8, 'position' => 1, 'kind' => 'individual',
			'visibility' => 'hidden', 'scheduled_at' => '2026-11-30 10:00:00', 'work_ids_snapshot' => null,
		) );
		$clock = $this->createStub( ClockInterface::class );
		$clock->method( 'now' )->willReturn( '2026-12-01 12:00:00' );
		$lessons = $this->createStub( LessonManager::class );
		$lessons->method( 'isPublished' )->willReturn( true );
		$lessons->method( 'get' )->willReturn( new \Inc\DTO\Course\LessonDTO( 8, 'math', 'Тема', array( new \Inc\DTO\Course\StepDTO( 'w10', \Inc\Enums\Course\StepType::Work, array( 'ref' => 10 ) ) ), 1, 'publish' ) );
		$snapshots = $this->createMock( RankingAssignmentSnapshotService::class );
		$snapshots->expects( self::once() )->method( 'capture' )->with( 42, array( 10 ), '2026-11-30 10:00:00', RankingAssignmentOrigin::Lesson );
		( new RankingScheduleHistoryService( $lessons, $snapshots, $clock ) )->captureBeforeMutation( $row );
	}

	public function test_archived_lesson_without_first_opening_date_is_marked_unknown(): void {
		$clock = $this->createStub( ClockInterface::class );
		$snapshots = $this->createMock( RankingAssignmentSnapshotService::class );
		$snapshots->expects( self::never() )->method( 'capture' );
		$snapshots->expects( self::once() )->method( 'markPublicationUnconfirmed' )->with( 42, array( 10 ), RankingAssignmentOrigin::Lesson );
		$service = new RankingScheduleHistoryService( $this->createStub( LessonManager::class ), $snapshots, $clock );
		$service->captureBeforeMutation( GroupLessonDTO::fromArray( array(
			'id' => 42, 'group_id' => 5, 'lesson_id' => 8, 'position' => 1,
			'visibility' => 'archived', 'scheduled_at' => '2026-11-29 10:00:00', 'work_ids_snapshot' => '[10]',
		) ) );
	}

	public function test_new_lesson_in_future_is_pending_until_opening(): void {
		$row = GroupLessonDTO::fromArray( array( 'id' => 42, 'group_id' => 5, 'lesson_id' => 8, 'position' => 1, 'kind' => 'individual', 'visibility' => 'hidden', 'scheduled_at' => '2026-12-01 10:00:00' ) );
		$lesson = new \Inc\DTO\Course\LessonDTO( 9, 'math', 'Тема', array( new \Inc\DTO\Course\StepDTO( 'w10', \Inc\Enums\Course\StepType::Work, array( 'ref' => 10 ) ) ), 1, 'publish' );
		$lessons = $this->createStub( LessonManager::class );
		$lessons->method( 'get' )->willReturn( $lesson );
		$clock = $this->createStub( ClockInterface::class );
		$clock->method( 'now' )->willReturn( '2026-11-30 12:00:00' );
		$snapshots = $this->createMock( RankingAssignmentSnapshotService::class );
		$snapshots->expects( self::once() )->method( 'markPending' )->with( 42, array( 10 ), RankingAssignmentOrigin::Lesson );
		$snapshots->expects( self::never() )->method( 'capture' );
		( new RankingScheduleHistoryService( $lessons, $snapshots, $clock ) )->captureNewLesson( $row, 9 );
	}
}
