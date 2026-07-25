<?php

declare( strict_types=1 );

namespace Unit\Modules\VideoLibrary;

use Inc\DTO\Course\GroupLessonDTO;
use Inc\Managers\Course\LessonManager;
use Inc\Modules\VideoLibrary\Callbacks\VideoLibraryCallbacks;
use Inc\Modules\VideoLibrary\Services\RecordingAlertService;
use Inc\Modules\VideoLibrary\Services\VideoRegistrationService;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use PHPUnit\Framework\TestCase;

/**
 * Админские AJAX модуля (З3): список занятий без записи и ручная вставка ссылки,
 * когда записи в реестре нет вовсе.
 */
class VideoLibraryCallbacksTest extends TestCase {

	private $registration;
	private $groupLessons;
	private $lessons;
	private $alerts;
	private VideoLibraryCallbacks $cb;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_ajax();
		$GLOBALS['_fs_test_can'] = true;

		$this->registration = $this->createMock( VideoRegistrationService::class );
		$this->groupLessons = $this->createMock( GroupLessonRepository::class );
		$this->lessons      = $this->createMock( LessonManager::class );
		$this->alerts       = $this->createMock( RecordingAlertService::class );

		$this->cb = new VideoLibraryCallbacks(
			$this->registration,
			$this->groupLessons,
			$this->lessons,
			$this->alerts
		);
	}

	private function row(): GroupLessonDTO {
		return new GroupLessonDTO(
			id: 42, groupId: 5, lessonId: 1, position: 0, workIdsSnapshot: null, extraWorkIds: array(),
			scheduledAt: null, endsAt: null, isPinned: false, teacherUserId: null, visibility: 'open',
			openedAt: null, homeworkDueAt: null, allowLate: true, recordingUrl: null,
			createdByUserId: null, updatedByUserId: null, status: 'held',
		);
	}

	public function test_pending_returns_count_and_lessons(): void {
		$this->alerts->method( 'countPending' )->willReturn( 2 );
		$this->alerts->method( 'pending' )->with( 30 )->willReturn( array( array( 'id' => 42 ) ) );
		$_POST = array();

		$r = fs_test_capture_json( fn() => $this->cb->ajaxPendingRecordings() );

		self::assertTrue( $r->success );
		self::assertSame( 2, $r->payload['count'] );
		self::assertCount( 1, $r->payload['lessons'] );
	}

	public function test_pending_limit_is_capped(): void {
		$this->alerts->method( 'countPending' )->willReturn( 0 );
		$this->alerts->expects( self::once() )->method( 'pending' )->with( 100 )->willReturn( array() );
		$_POST = array( 'limit' => '5000' );

		self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxPendingRecordings() )->success );
	}

	public function test_set_lesson_recording_url_saves_pointer(): void {
		$this->groupLessons->method( 'find' )->with( 42 )->willReturn( $this->row() );
		$this->groupLessons->expects( self::once() )
			->method( 'setRecordingUrl' )
			->with( 42, 'https://example.com/rec.mp4' );

		$_POST = array( 'group_lesson_id' => '42', 'recording_url' => 'https://example.com/rec.mp4' );

		self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxSetLessonRecordingUrl() )->success );
	}

	public function test_set_lesson_recording_url_empty_clears_pointer(): void {
		$this->groupLessons->method( 'find' )->willReturn( $this->row() );
		$this->groupLessons->expects( self::once() )->method( 'setRecordingUrl' )->with( 42, null );

		$_POST = array( 'group_lesson_id' => '42', 'recording_url' => '' );

		self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxSetLessonRecordingUrl() )->success );
	}

	public function test_set_lesson_recording_url_rejects_unknown_lesson(): void {
		$this->groupLessons->method( 'find' )->willReturn( null );
		$this->groupLessons->expects( self::never() )->method( 'setRecordingUrl' );

		$_POST = array( 'group_lesson_id' => '404', 'recording_url' => 'https://example.com/rec.mp4' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxSetLessonRecordingUrl() )->success );
	}

	public function test_set_lesson_recording_url_denied_without_capability(): void {
		$GLOBALS['_fs_test_can'] = false;
		$this->groupLessons->expects( self::never() )->method( 'setRecordingUrl' );

		$_POST = array( 'group_lesson_id' => '42', 'recording_url' => 'https://example.com/rec.mp4' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxSetLessonRecordingUrl() )->success );
	}
}
