<?php

declare( strict_types=1 );

namespace Unit\Modules\VideoLibrary;

use Inc\DTO\Course\GroupLessonDTO;
use Inc\Enums\Course\LessonStatus;
use Inc\Modules\VideoLibrary\DTO\VideoRecordingDTO;
use Inc\Modules\VideoLibrary\DTO\VideoRecordingInputDTO;
use Inc\Modules\VideoLibrary\Repositories\VideoRecordingRepository;
use Inc\Modules\VideoLibrary\Services\VideoLessonResolver;
use Inc\Modules\VideoLibrary\Services\VideoRegistrationService;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use PHPUnit\Framework\TestCase;

class VideoRegistrationServiceTest extends TestCase {

	private VideoRecordingRepository $recordings;
	private VideoLessonResolver $resolver;
	private GroupLessonRepository $groupLessons;
	private GroupsRepository $groups;
	private VideoRegistrationService $service;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_fs_test_actions']  = array();
		$GLOBALS['_fs_test_users_by'] = array();
		$GLOBALS['_fs_test_timezone'] = 'Europe/Kaliningrad'; // UTC+2

		$this->recordings   = $this->createMock( VideoRecordingRepository::class );
		$this->resolver     = $this->createMock( VideoLessonResolver::class );
		$this->groupLessons = $this->createMock( GroupLessonRepository::class );
		$this->groups       = $this->createMock( GroupsRepository::class );

		// По умолчанию группа консистентна с lms-блоком (без WARNING-шума).
		$this->groups->method( 'findById' )->willReturn( (object) array(
			'id'         => 3,
			'course_id'  => 42,
			'teacher_id' => 7,
		) );

		$this->service = new VideoRegistrationService(
			$this->recordings,
			$this->resolver,
			$this->groupLessons,
			$this->groups,
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_fs_test_timezone'], $GLOBALS['_fs_test_users_by'], $GLOBALS['_fs_test_actions'] );
		parent::tearDown();
	}

	private function input( array $over = array() ): VideoRecordingInputDTO {
		return new VideoRecordingInputDTO(
			s3Bucket:        'test-bucket',
			s3Key:           $over['s3_key'] ?? 'videos/kege-1/rec.webm',
			manifestKey:     null,
			groupSlug:       'kege-1',
			groupId:         array_key_exists( 'group_id', $over ) ? $over['group_id'] : 3,
			courseId:        $over['course_id'] ?? 42,
			teacherId:       array_key_exists( 'teacher_id', $over ) ? $over['teacher_id'] : 7,
			teacherUsername: $over['teacher_username'] ?? null,
			recordedAt:      $over['recorded_at'] ?? '2026-07-08T16:04:45+03:00',
			sizeBytes:       100,
			sha256:          str_repeat( 'a', 64 ),
			durationSec:     null,
			payload:         '{}',
		);
	}

	private function recording( array $over = array() ): VideoRecordingDTO {
		return new VideoRecordingDTO(
			id:            $over['id'] ?? 10,
			s3Bucket:      'test-bucket',
			s3Key:         'videos/kege-1/rec.webm',
			manifestKey:   null,
			groupSlug:     'kege-1',
			groupId:       3,
			teacherUserId: null,
			groupLessonId: $over['group_lesson_id'] ?? null,
			status:        $over['status'] ?? 'unmatched',
			recordedAt:    '2026-07-08 15:04:45',
			sizeBytes:     100,
			sha256:        str_repeat( 'a', 64 ),
			durationSec:   null,
			payload:       '{}',
			createdAt:     '2026-07-08 17:00:00',
			updatedAt:     '2026-07-08 17:00:00',
		);
	}

	private function lesson( int $id, string $status = 'scheduled' ): GroupLessonDTO {
		return new GroupLessonDTO(
			id: $id, groupId: 3, lessonId: 10, position: 0,
			workIdsSnapshot: null, extraWorkIds: array(),
			scheduledAt: '2026-07-08 15:00:00', endsAt: null, isPinned: false,
			teacherUserId: null, visibility: 'open', openedAt: null,
			homeworkDueAt: null, allowLate: true,
			recordingUrl: 's3://test-bucket/videos/kege-1/rec.webm',
			createdByUserId: null, updatedByUserId: null,
			status: $status,
		);
	}

	private function upsertReturns( int $id, bool $isNew, ?VideoRecordingDTO $existing = null ): void {
		$this->recordings->method( 'upsertByS3Key' )->willReturn( array(
			'id'       => $id,
			'isNew'    => $isNew,
			'existing' => $existing,
		) );
	}

	// ── Матч ─────────────────────────────────────────────────────────────────

	public function test_match_attaches_writes_pointer_and_sets_held(): void {
		$this->upsertReturns( 10, true );
		$this->resolver->method( 'resolve' )->willReturn( array( 'group_lesson_id' => 55, 'reason' => 'matched' ) );
		$this->groupLessons->method( 'find' )->with( 55 )->willReturn( $this->lesson( 55, 'scheduled' ) );

		$this->recordings->expects( self::once() )->method( 'attach' )->with( 10, 55 );
		$this->groupLessons->expects( self::once() )->method( 'setRecordingUrl' )
			->with( 55, 's3://test-bucket/videos/kege-1/rec.webm' );
		$this->groupLessons->expects( self::once() )->method( 'setStatus' )
			->with( 55, LessonStatus::Held );

		$result = $this->service->register( $this->input() );

		self::assertTrue( $result['matched'] );
		self::assertSame( 55, $result['group_lesson_id'] );
		self::assertSame( 10, $result['recording_id'] );
	}

	public function test_held_is_not_set_over_cancelled(): void {
		$this->upsertReturns( 10, true );
		$this->resolver->method( 'resolve' )->willReturn( array( 'group_lesson_id' => 55, 'reason' => 'matched' ) );
		$this->groupLessons->method( 'find' )->willReturn( $this->lesson( 55, 'cancelled' ) );

		$this->groupLessons->expects( self::never() )->method( 'setStatus' );
		$this->groupLessons->expects( self::once() )->method( 'setRecordingUrl' );

		$this->service->register( $this->input() );
	}

	public function test_recorded_at_is_normalized_to_site_timezone(): void {
		// +03:00 → Europe/Kaliningrad (UTC+2): 16:04:45 → 15:04:45.
		$captured = null;
		$this->recordings->method( 'upsertByS3Key' )->willReturnCallback(
			function ( VideoRecordingInputDTO $i, string $recordedAtLocal ) use ( &$captured ): array {
				$captured = $recordedAtLocal;
				return array( 'id' => 10, 'isNew' => true, 'existing' => null );
			}
		);
		$this->resolver->method( 'resolve' )->willReturn( array( 'group_lesson_id' => null, 'reason' => 'no_candidates' ) );

		$this->service->register( $this->input() );

		self::assertSame( '2026-07-08 15:04:45', $captured );
	}

	// ── Идемпотентность ──────────────────────────────────────────────────────

	public function test_repeat_of_matched_recording_keeps_binding_and_skips_resolve(): void {
		$this->upsertReturns( 10, false, $this->recording( array( 'status' => 'matched', 'group_lesson_id' => 55 ) ) );

		$this->resolver->expects( self::never() )->method( 'resolve' );
		$this->recordings->expects( self::never() )->method( 'attach' );
		$this->groupLessons->expects( self::never() )->method( 'setRecordingUrl' );

		$result = $this->service->register( $this->input() );

		self::assertTrue( $result['matched'] );
		self::assertSame( 55, $result['group_lesson_id'] );
	}

	public function test_repeat_of_unmatched_recording_is_reresolved(): void {
		$this->upsertReturns( 10, false, $this->recording( array( 'status' => 'unmatched' ) ) );
		$this->resolver->expects( self::once() )->method( 'resolve' )
			->willReturn( array( 'group_lesson_id' => 55, 'reason' => 'matched' ) );
		$this->groupLessons->method( 'find' )->willReturn( $this->lesson( 55 ) );

		$this->recordings->expects( self::once() )->method( 'attach' )->with( 10, 55 );

		$result = $this->service->register( $this->input() );

		self::assertTrue( $result['matched'] );
	}

	// ── Unmatched-путь ───────────────────────────────────────────────────────

	public function test_unmatched_returns_200_semantics_and_fires_action(): void {
		$this->upsertReturns( 10, true );
		$this->resolver->method( 'resolve' )->willReturn( array( 'group_lesson_id' => null, 'reason' => 'no_candidates' ) );

		$this->recordings->expects( self::never() )->method( 'attach' );

		$result = $this->service->register( $this->input() );

		self::assertFalse( $result['matched'] );
		self::assertNull( $result['group_lesson_id'] );

		$hooks = array_column( $GLOBALS['_fs_test_actions'], 'hook' );
		self::assertContains( 'fs_lms_video_registered', $hooks );
	}

	// ── Индивидуальная ветка ─────────────────────────────────────────────────

	public function test_teacher_id_takes_priority_over_username(): void {
		$this->upsertReturns( 10, true );
		// teacher_id задан — get_user_by не должен даже понадобиться (не стабим _fs_test_users_by).
		$this->resolver->expects( self::once() )->method( 'resolve' )
			->with( self::anything(), null, 7 )
			->willReturn( array( 'group_lesson_id' => null, 'reason' => 'no_candidates' ) );

		$this->service->register( $this->input( array( 'group_id' => null, 'teacher_id' => 7, 'teacher_username' => 'i.petrov' ) ) );
	}

	public function test_teacher_username_is_resolved_to_user_id_when_teacher_id_absent(): void {
		$user             = new \WP_User();
		$user->ID         = 7;
		$user->user_login = 'i.petrov';
		$GLOBALS['_fs_test_users_by']['login']['i.petrov'] = $user;

		$this->upsertReturns( 10, true );
		$this->resolver->expects( self::once() )->method( 'resolve' )
			->with( self::anything(), null, 7 )
			->willReturn( array( 'group_lesson_id' => null, 'reason' => 'no_candidates' ) );

		$this->service->register( $this->input( array( 'group_id' => null, 'teacher_id' => null, 'teacher_username' => 'i.petrov' ) ) );
	}

	public function test_unknown_teacher_username_goes_unmatched(): void {
		$this->upsertReturns( 10, true );
		$this->resolver->expects( self::once() )->method( 'resolve' )
			->with( self::anything(), null, null )
			->willReturn( array( 'group_lesson_id' => null, 'reason' => 'no_candidates' ) );

		$result = $this->service->register( $this->input( array( 'group_id' => null, 'teacher_id' => null, 'teacher_username' => 'ghost' ) ) );

		self::assertFalse( $result['matched'] );
	}

	// ── Валидация ────────────────────────────────────────────────────────────

	public function test_unparseable_recorded_at_throws(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->service->register( $this->input( array( 'recorded_at' => 'не дата' ) ) );
	}

	// ── Ручная привязка/отвязка (V9) ─────────────────────────────────────────

	public function test_attach_manually_binds_like_auto_match(): void {
		$this->recordings->method( 'find' )->with( 10 )->willReturn( $this->recording() );
		$this->groupLessons->method( 'find' )->with( 55 )->willReturn( $this->lesson( 55, 'scheduled' ) );

		$this->recordings->expects( self::once() )->method( 'attach' )->with( 10, 55 );
		$this->groupLessons->expects( self::once() )->method( 'setRecordingUrl' )
			->with( 55, 's3://test-bucket/videos/kege-1/rec.webm' );
		$this->groupLessons->expects( self::once() )->method( 'setStatus' )->with( 55, LessonStatus::Held );

		self::assertTrue( $this->service->attachManually( 10, 55 ) );
	}

	public function test_attach_manually_fails_on_missing_recording(): void {
		$this->recordings->method( 'find' )->willReturn( null );

		self::assertFalse( $this->service->attachManually( 10, 55 ) );
	}

	public function test_detach_manually_clears_pointer_only_when_it_points_to_this_recording(): void {
		$this->recordings->method( 'find' )->willReturn(
			$this->recording( array( 'status' => 'matched', 'group_lesson_id' => 55 ) )
		);
		$this->groupLessons->method( 'find' )->with( 55 )->willReturn( $this->lesson( 55, 'held' ) );

		$this->groupLessons->expects( self::once() )->method( 'setRecordingUrl' )->with( 55, null );
		$this->groupLessons->expects( self::never() )->method( 'setStatus' );
		$this->recordings->expects( self::once() )->method( 'detach' )->with( 10 )->willReturn( true );

		self::assertTrue( $this->service->detachManually( 10 ) );
	}
}
