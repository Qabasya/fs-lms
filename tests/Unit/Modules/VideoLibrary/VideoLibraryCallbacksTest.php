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
	private \Inc\Services\Course\GroupAccessGuard&\PHPUnit\Framework\MockObject\MockObject $guard;
	private VideoLibraryCallbacks $cb;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_ajax();
		$GLOBALS['_fs_test_can'] = true;

		$this->registration = $this->createMock( VideoRegistrationService::class );
		$this->groupLessons = $this->createMock( GroupLessonRepository::class );
		$this->lessons      = $this->createMock( LessonManager::class );
		$this->alerts       = $this->createMock( RecordingAlertService::class );
		$this->guard        = $this->createMock( \Inc\Services\Course\GroupAccessGuard::class );

		$this->cb = new VideoLibraryCallbacks(
			$this->registration,
			$this->groupLessons,
			$this->lessons,
			$this->alerts,
			$this->guard
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
			->with( 42, 's3://bucket/videos/rec.webm' );

		$_POST = array( 'group_lesson_id' => '42', 'recording_url' => 's3://bucket/videos/rec.webm' );

		self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxSetLessonRecordingUrl() )->success );
	}

	/** Прямая ссылка (облако) — во внешнюю ссылку занятия, не в указатель хранилища. */
	public function test_set_lesson_recording_url_routes_http_to_link(): void {
		$this->groupLessons->method( 'find' )->with( 42 )->willReturn( $this->row() );
		$this->groupLessons->expects( self::never() )->method( 'setRecordingUrl' );
		$this->groupLessons->expects( self::once() )
			->method( 'setRecordingLink' )
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

	/** Дополнительное занятие — по строке на ученика: ссылка ложится на все строки, а не на одну. */
	public function test_set_lesson_recording_url_covers_all_extra_lesson_rows(): void {
		$this->groupLessons->method( 'find' )->with( 42 )->willReturn( $this->row() );
		$this->groupLessons->method( 'extraSiblingIds' )->willReturn( array( 42, 43, 44 ) );
		$saved = array();
		$this->groupLessons->method( 'setRecordingLink' )->willReturnCallback(
			static function ( int $id ) use ( &$saved ): bool {
				$saved[] = $id;
				return true;
			}
		);

		$_POST = array( 'group_lesson_id' => '42', 'recording_url' => 'https://example.com/rec.mp4' );

		self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxSetLessonRecordingUrl() )->success );
		self::assertSame( array( 42, 43, 44 ), $saved );
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

	private function recording( int $id ): \Inc\Modules\VideoLibrary\DTO\VideoRecordingDTO {
		return \Inc\Modules\VideoLibrary\DTO\VideoRecordingDTO::fromRow( (object) array(
			'id' => $id, 's3_bucket' => 'b', 's3_key' => 'g/rec.webm', 'group_slug' => 'g', 'status' => 'unmatched',
			'recorded_at' => '2026-10-19 10:00:00', 'size_bytes' => 1, 'sha256' => 'h', 'created_at' => '2026-10-19 10:00:00', 'updated_at' => '2026-10-19 10:00:00',
		) );
	}

	public function test_teacher_attach_binds_candidate_recording(): void {
		$this->groupLessons->method( 'find' )->with( 42 )->willReturn( $this->row() );
		$this->guard->method( 'canManage' )->willReturn( true );
		$this->alerts->method( 'candidatesFor' )->willReturn( array( $this->recording( 7 ) ) );
		$this->registration->expects( self::once() )->method( 'attachManually' )->with( 7, 42 )->willReturn( true );
		$_POST = array( 'group_lesson_id' => '42', 'recording_id' => '7' );

		self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxTeacherAttach() )->success );
	}

	public function test_teacher_attach_rejects_recording_outside_candidates(): void {
		$this->groupLessons->method( 'find' )->willReturn( $this->row() );
		$this->guard->method( 'canManage' )->willReturn( true );
		$this->alerts->method( 'candidatesFor' )->willReturn( array( $this->recording( 7 ) ) );
		$this->registration->expects( self::never() )->method( 'attachManually' );
		$_POST = array( 'group_lesson_id' => '42', 'recording_id' => '99' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxTeacherAttach() )->success );
	}

	public function test_teacher_attach_denied_without_group_access(): void {
		$this->groupLessons->method( 'find' )->willReturn( $this->row() );
		$this->guard->method( 'canManage' )->willReturn( false );
		$this->registration->expects( self::never() )->method( 'attachManually' );
		$_POST = array( 'group_lesson_id' => '42', 'recording_id' => '7' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxTeacherAttach() )->success );
	}
}
