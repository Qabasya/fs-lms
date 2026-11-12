<?php

declare( strict_types=1 );

namespace Unit\Services\Course;

use Inc\Contracts\ClockInterface;
use Inc\DTO\Course\GroupLessonDTO;
use Inc\DTO\Course\LessonDTO;
use Inc\Managers\Course\LessonManager;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Services\Course\RankingAssignmentSnapshotService;
use Inc\Services\Course\RankingLessonPublicationService;
use PHPUnit\Framework\TestCase;

class RankingLessonPublicationServiceTest extends TestCase {
	public function test_late_publication_stages_then_commits_issue(): void {
		$lessons = $this->createStub( LessonManager::class );
		$lessons->method( 'get' )->willReturn( LessonDTO::fromArray( array( 'id' => 9, 'subject_key' => 'math', 'steps' => array() ) ) );
		$lessons->method( 'isPublished' )->willReturn( false, true );
		$groups = $this->createStub( GroupLessonRepository::class );
		$groups->method( 'listByLessonId' )->willReturn( array( new GroupLessonDTO( id: 3, groupId: 5, lessonId: 9, position: 0, workIdsSnapshot: array( 7 ), extraWorkIds: array(), scheduledAt: '2026-11-30 10:00:00', endsAt: null, isPinned: false, teacherUserId: null, visibility: 'hidden', openedAt: null, homeworkDueAt: null, allowLate: true, recordingUrl: null, createdByUserId: null, updatedByUserId: null ) ) );
		$clock = $this->createStub( ClockInterface::class );
		$clock->method( 'now' )->willReturn( '2026-12-02 10:00:00' );
		$snapshots = $this->createMock( RankingAssignmentSnapshotService::class );
		$snapshots->method( 'synchronizedWrite' )->willReturnCallback( static function ( callable $prepare, callable $write ): void { $prepare(); $write(); } );
		$snapshots->method( 'synchronized' )->willReturnCallback( static function ( callable $operation ): void { $operation(); } );
		$calls = array();
		$snapshots->expects( self::once() )->method( 'markPublicationUnconfirmed' )->with( 3, array( 7 ), \Inc\Enums\Course\RankingAssignmentOrigin::Lesson )->willReturnCallback( static function () use ( &$calls ): void { $calls[] = 'prepare'; } );
		$snapshots->expects( self::once() )->method( 'capture' )->with( 3, array( 7 ), null, \Inc\Enums\Course\RankingAssignmentOrigin::Lesson )->willReturnCallback( static function () use ( &$calls ): void { $calls[] = 'capture'; } );
		$service = new RankingLessonPublicationService( $lessons, $groups, $snapshots, $clock );
		self::assertTrue( $service->publish( 9, static function () use ( &$calls ): bool { $calls[] = 'publish'; return true; } ) );
		self::assertSame( array( 'prepare', 'publish', 'capture' ), $calls );
	}
	public function test_publication_preserves_distinct_origins_and_marks_overlap_unknown(): void {
		$lessons = $this->createStub( LessonManager::class );
		$lessons->method( 'get' )->willReturn( LessonDTO::fromArray( array( 'id' => 9, 'subject_key' => 'math', 'steps' => array() ) ) );
		$lessons->method( 'isPublished' )->willReturn( false, true );
		$groups = $this->createStub( GroupLessonRepository::class );
		$groups->method( 'listByLessonId' )->willReturn( array( new GroupLessonDTO( id: 3, groupId: 5, lessonId: 9, position: 0, workIdsSnapshot: array( 7, 9 ), extraWorkIds: array( 8, 9 ), scheduledAt: '2026-11-30 10:00:00', endsAt: null, isPinned: false, teacherUserId: null, visibility: 'hidden', openedAt: null, homeworkDueAt: null, allowLate: true, recordingUrl: null, createdByUserId: null, updatedByUserId: null ) ) );
		$clock = $this->createStub( ClockInterface::class );
		$clock->method( 'now' )->willReturn( '2026-12-02 10:00:00' );
		$snapshots = $this->createMock( RankingAssignmentSnapshotService::class );
		$snapshots->method( 'synchronizedWrite' )->willReturnCallback( static function ( callable $prepare, callable $write ): void { $prepare(); $write(); } );
		$snapshots->method( 'synchronized' )->willReturnCallback( static function ( callable $operation ): void { $operation(); } );
		$observed = array();
		$snapshots->expects( self::exactly( 3 ) )->method( 'markPublicationUnconfirmed' )->willReturnCallback( static function ( int $id, array $ids, ?\Inc\Enums\Course\RankingAssignmentOrigin $origin ) use ( &$observed ): void { $observed[ $ids[0] ] = $origin?->value; } );
		$snapshots->expects( self::exactly( 3 ) )->method( 'capture' );
		self::assertTrue( ( new RankingLessonPublicationService( $lessons, $groups, $snapshots, $clock ) )->publish( 9, static fn(): bool => true ) );
		self::assertSame( array( 7 => 'lesson', 9 => null, 8 => 'extra' ), $observed );
	}

	public function test_failed_publication_never_confirms_prepared_issue(): void {
		$lessons = $this->createStub( LessonManager::class );
		$lessons->method( 'get' )->willReturn( LessonDTO::fromArray( array( 'id' => 9, 'subject_key' => 'math', 'steps' => array() ) ) );
		$lessons->method( 'isPublished' )->willReturn( false );
		$groups = $this->createStub( GroupLessonRepository::class );
		$groups->method( 'listByLessonId' )->willReturn( array( new GroupLessonDTO( id: 3, groupId: 5, lessonId: 9, position: 0, workIdsSnapshot: array( 7 ), extraWorkIds: array(), scheduledAt: '2026-11-30 10:00:00', endsAt: null, isPinned: false, teacherUserId: null, visibility: 'hidden', openedAt: null, homeworkDueAt: null, allowLate: true, recordingUrl: null, createdByUserId: null, updatedByUserId: null ) ) );
		$clock = $this->createStub( ClockInterface::class );
		$clock->method( 'now' )->willReturn( '2026-12-02 10:00:00' );
		$snapshots = $this->createMock( RankingAssignmentSnapshotService::class );
		$snapshots->method( 'synchronizedWrite' )->willReturnCallback( static function ( callable $prepare, callable $write ): void { $prepare(); $write(); } );
		$snapshots->expects( self::once() )->method( 'markPublicationUnconfirmed' );
		$snapshots->expects( self::never() )->method( 'capture' );
		$service = new RankingLessonPublicationService( $lessons, $groups, $snapshots, $clock );
		self::assertFalse( $service->publish( 9, static fn(): bool => false ) );
	}
	public function test_publication_across_period_boundary_remains_unconfirmed(): void {
		$lessons = $this->createStub( LessonManager::class );
		$lessons->method( 'get' )->willReturn( LessonDTO::fromArray( array( 'id' => 9, 'subject_key' => 'math', 'steps' => array() ) ) );
		$lessons->method( 'isPublished' )->willReturn( false, true );
		$groups = $this->createStub( GroupLessonRepository::class );
		$groups->method( 'listByLessonId' )->willReturn( array( new GroupLessonDTO( id: 3, groupId: 5, lessonId: 9, position: 0, workIdsSnapshot: array( 7 ), extraWorkIds: array(), scheduledAt: '2026-11-29 10:00:00', endsAt: null, isPinned: false, teacherUserId: null, visibility: 'hidden', openedAt: null, homeworkDueAt: null, allowLate: true, recordingUrl: null, createdByUserId: null, updatedByUserId: null ) ) );
		$clock = $this->createStub( ClockInterface::class );
		$clock->method( 'now' )->willReturn( '2026-11-30 23:59:59', '2026-11-30 23:59:59', '2026-12-01 00:00:00' );
		$snapshots = $this->createMock( RankingAssignmentSnapshotService::class );
		$snapshots->method( 'synchronizedWrite' )->willReturnCallback( static function ( callable $prepare, callable $write ): void { $prepare(); $write(); } );
		$snapshots->expects( self::once() )->method( 'markPublicationUnconfirmed' );
		$snapshots->expects( self::never() )->method( 'capture' );
		self::assertTrue( ( new RankingLessonPublicationService( $lessons, $groups, $snapshots, $clock ) )->publish( 9, static fn(): bool => true ) );
	}
}
