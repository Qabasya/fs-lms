<?php

declare(strict_types=1);

namespace Unit\Modules\VideoLibrary;

use Inc\DTO\Course\GroupLessonDTO;
use Inc\Modules\VideoLibrary\Repositories\VideoAccessLogRepository;
use Inc\Modules\VideoLibrary\Services\VideoAccessLogService;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use PHPUnit\Framework\TestCase;

/**
 * Журнал доступа: выдача пишется только вошедшим, просмотр — только по записи
 * из S3 (ключ сервер берёт из занятия, клиентское значение не доверяем).
 */
class VideoAccessLogServiceTest extends TestCase {

	private VideoAccessLogRepository&\PHPUnit\Framework\MockObject\MockObject $log;
	private GroupLessonRepository $groupLessons;
	private VideoAccessLogService $service;
	private \Inc\Modules\VideoLibrary\Services\VideoWatchAccessService $access;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_fs_test_user_id'] = 77;
		$this->log          = $this->createMock( VideoAccessLogRepository::class );
		$this->groupLessons = $this->createStub( GroupLessonRepository::class );
		$this->access = $this->createMock( \Inc\Modules\VideoLibrary\Services\VideoWatchAccessService::class );
		$this->service      = new VideoAccessLogService( $this->log, $this->groupLessons, $this->access );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_fs_test_user_id'] );
		parent::tearDown();
	}

	private function lesson( ?string $recordingUrl ): GroupLessonDTO {
		return new GroupLessonDTO(
			id: 42, groupId: 5, lessonId: 1, position: 0, workIdsSnapshot: null, extraWorkIds: array(),
			scheduledAt: null, endsAt: null, isPinned: false, teacherUserId: null, visibility: 'open',
			openedAt: null, homeworkDueAt: null, allowLate: true, recordingUrl: $recordingUrl,
			createdByUserId: null, updatedByUserId: null, status: 'held',
		);
	}

	public function test_presign_logged_for_logged_in_user(): void {
		$this->log->expects( self::once() )
			->method( 'touch' )
			->with( 77, self::anything(), 42, 'bucket', 'videos/rec.webm', self::anything(), 1, 0 );

		$this->service->logPresign( 'bucket', 'videos/rec.webm', 42 );
	}

	public function test_presign_skipped_for_guest(): void {
		unset( $GLOBALS['_fs_test_user_id'] );
		$this->log->expects( self::never() )->method( 'touch' );

		$this->service->logPresign( 'bucket', 'videos/rec.webm', 42 );
	}

	public function test_watch_uses_s3_key_from_lesson(): void {
		$this->access->method( 'canWatch' )->willReturn( true );
		$this->groupLessons->method( 'find' )->with( 42 )
			->willReturn( $this->lesson( 's3://bucket/videos/rec.webm' ) );
		$this->log->expects( self::once() )
			->method( 'touch' )
			->with( 77, self::anything(), 42, 'bucket', 'videos/rec.webm', self::anything(), 0, 120 );

		$this->service->logWatch( 42, 120 );
	}

	public function test_watch_ignored_for_external_recording(): void {
		$this->groupLessons->method( 'find' )
			->willReturn( $this->lesson( 'https://vk.com/video-1_2' ) );
		$this->log->expects( self::never() )->method( 'touch' );

		$this->service->logWatch( 42, 120 );
	}

	public function test_watch_portion_is_capped(): void {
		$this->access->method( 'canWatch' )->willReturn( true );
		$this->groupLessons->method( 'find' )
			->willReturn( $this->lesson( 's3://bucket/videos/rec.webm' ) );
		$this->log->expects( self::once() )
			->method( 'touch' )
			->with( 77, self::anything(), 42, 'bucket', 'videos/rec.webm', self::anything(), 0, 3600 );

		$this->service->logWatch( 42, 999999 );
	}

	public function test_denied_lesson_never_writes_telemetry(): void {
		$this->groupLessons->method( 'find' )->willReturn( $this->lesson( 's3://bucket/private.webm' ) );
		$this->access->expects( self::once() )->method( 'canWatch' )->with( 77, self::anything() )->willReturn( false );
		$this->log->expects( self::never() )->method( 'touch' );
		$this->service->logWatch( 42, 120 );
	}
}
