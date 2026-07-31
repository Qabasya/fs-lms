<?php

declare( strict_types=1 );

namespace Unit\Callbacks\Course;

use Inc\Callbacks\Course\LessonDeliveryCallbacks;
use Inc\DTO\Course\WorkDTO;
use Inc\Enums\Course\WorkType;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Services\Course\EffectiveWorksResolver;
use Inc\Services\Course\GroupAccessGuard;
use Inc\Services\Group\ProgramCompositionService;
use PHPUnit\Framework\TestCase;
use Tests\Support\ProgramRowFixtures;

/**
 * Доставка занятия: работы, дедлайны (T12.3, D13) и ссылка на запись (З3).
 */
class LessonDeliveryCallbacksTest extends TestCase {

	use ProgramRowFixtures;

	private EffectiveWorksResolver    $works;
	private GroupLessonRepository     $groupLessons;
	private ProgramCompositionService $program;
	private GroupAccessGuard          $guard;
	private LessonDeliveryCallbacks   $cb;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_ajax();
		$this->works        = $this->createMock( EffectiveWorksResolver::class );
		$this->groupLessons = $this->createMock( GroupLessonRepository::class );
		$this->program      = $this->createMock( ProgramCompositionService::class );
		$this->guard        = $this->createMock( GroupAccessGuard::class );

		$this->cb = new LessonDeliveryCallbacks( $this->works, $this->groupLessons, $this->program, $this->guard );
	}

	private function work( int $id, string $title ): WorkDTO {
		return new WorkDTO(
			id: $id, subjectKey: 'inf', title: $title, workType: WorkType::Practice,
			itemIds: array(), instructions: '', authorId: 1, status: 'publish',
		);
	}

	public function test_set_extra_works_passes_int_list(): void {
		$this->works->expects( $this->once() )->method( 'setExtraWorks' )->with( 42, array( 501, 502 ), $this->anything() );
		$_POST = array( 'group_lesson_id' => '42', 'work_ids' => array( '501', '502' ) );

		self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxSetLessonExtraWorks() )->success );
	}

	public function test_get_work_deadlines_returns_effective_works_with_current_deadlines(): void {
		$row = $this->programRow( array( 501 => '2026-08-01 12:00:00' ) );
		$this->program->method( 'getProgramRow' )->with( 42 )->willReturn( $row );
		$this->guard->method( 'canManage' )->with( 5, $this->anything() )->willReturn( true );
		$this->works->method( 'resolve' )->with( $row )->willReturn( array(
			$this->work( 501, 'Практика №1' ),
			$this->work( 502, 'Практика №2' ),
		) );
		$_POST = array( 'group_lesson_id' => '42' );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxGetWorkDeadlines() );

		self::assertTrue( $r->success );
		self::assertCount( 2, $r->payload['works'] );
		self::assertSame( '2026-08-01 12:00:00', $r->payload['works'][0]['deadline'] );
		self::assertNull( $r->payload['works'][1]['deadline'] );
	}

	public function test_get_work_deadlines_denied_when_not_manager(): void {
		$this->program->method( 'getProgramRow' )->willReturn( $this->programRow() );
		$this->guard->method( 'canManage' )->willReturn( false );
		$this->works->expects( $this->never() )->method( 'resolve' );
		$_POST = array( 'group_lesson_id' => '42' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxGetWorkDeadlines() )->success );
	}

	public function test_get_work_deadlines_denied_when_row_missing(): void {
		$this->program->method( 'getProgramRow' )->willReturn( null );
		$_POST = array( 'group_lesson_id' => '999' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxGetWorkDeadlines() )->success );
	}

	public function test_save_work_deadlines_delegates_sanitized_map(): void {
		$this->program->method( 'getProgramRow' )->with( 42 )->willReturn( $this->programRow() );
		$this->guard->method( 'canManage' )->with( 5, $this->anything() )->willReturn( true );
		$this->groupLessons->expects( $this->once() )->method( 'setWorkDeadlines' )
			->with( 42, array( 501 => '2026-08-01 12:00:00' ) );
		$_POST = array(
			'group_lesson_id' => '42',
			'deadlines'       => json_encode( array( '501' => '2026-08-01 12:00:00', '502' => '' ) ),
		);

		self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxSaveWorkDeadlines() )->success );
	}

	public function test_save_work_deadlines_denied_when_not_manager(): void {
		$this->program->method( 'getProgramRow' )->willReturn( $this->programRow() );
		$this->guard->method( 'canManage' )->willReturn( false );
		$this->groupLessons->expects( $this->never() )->method( 'setWorkDeadlines' );
		$_POST = array( 'group_lesson_id' => '42', 'deadlines' => '{}' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxSaveWorkDeadlines() )->success );
	}

	public function test_save_work_deadlines_rejects_malformed_json(): void {
		$this->program->method( 'getProgramRow' )->willReturn( $this->programRow() );
		$this->guard->method( 'canManage' )->willReturn( true );
		$this->groupLessons->expects( $this->never() )->method( 'setWorkDeadlines' );
		$_POST = array( 'group_lesson_id' => '42', 'deadlines' => 'not-json' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxSaveWorkDeadlines() )->success );
	}

	/** T1.8: дедлайны — delivery, не структура/расписание — работают даже при lock КТП. */
	public function test_save_work_deadlines_not_blocked_when_program_locked(): void {
		$this->program->method( 'getProgramRow' )->willReturn( $this->programRow() );
		$this->guard->method( 'canManage' )->willReturn( true );
		$this->program->method( 'isProgramLocked' )->willReturn( true );
		$this->groupLessons->expects( $this->once() )->method( 'setWorkDeadlines' );
		$_POST = array( 'group_lesson_id' => '42', 'deadlines' => json_encode( array( '501' => '2026-08-01 12:00:00' ) ) );

		self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxSaveWorkDeadlines() )->success );
	}

	public function test_set_recording_url_saves_pointer(): void {
		$this->program->method( 'getProgramRow' )->with( 42 )->willReturn( $this->programRow() );
		$this->guard->method( 'canManage' )->with( 5, $this->anything() )->willReturn( true );
		$this->groupLessons->expects( $this->once() )
			->method( 'setRecordingUrl' )
			->with( 42, 's3://bucket/videos/rec.webm' );

		$_POST = array( 'group_lesson_id' => '42', 'recording_url' => 's3://bucket/videos/rec.webm' );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxSetRecordingUrl() );

		self::assertTrue( $r->success );
		self::assertSame( 's3://bucket/videos/rec.webm', $r->payload['recording_url'] );
	}

	public function test_set_recording_url_empty_clears_pointer(): void {
		$this->program->method( 'getProgramRow' )->willReturn( $this->programRow() );
		$this->guard->method( 'canManage' )->willReturn( true );
		$this->groupLessons->expects( $this->once() )->method( 'setRecordingUrl' )->with( 42, null );

		$_POST = array( 'group_lesson_id' => '42', 'recording_url' => '' );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxSetRecordingUrl() );

		self::assertTrue( $r->success );
		self::assertNull( $r->payload['recording_url'] );
	}

	public function test_set_recording_url_denied_for_foreign_group(): void {
		$this->program->method( 'getProgramRow' )->willReturn( $this->programRow() );
		$this->guard->method( 'canManage' )->willReturn( false );
		$this->groupLessons->expects( $this->never() )->method( 'setRecordingUrl' );

		$_POST = array( 'group_lesson_id' => '42', 'recording_url' => 'https://example.com/rec.mp4' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxSetRecordingUrl() )->success );
	}
}
