<?php

declare( strict_types=1 );

namespace Unit\Services\Course;

use Inc\DTO\Course\GroupLessonDTO;
use Inc\DTO\Course\LessonDTO;
use Inc\DTO\Course\StepDTO;
use Inc\Enums\Course\GateState;
use Inc\Enums\Course\ProgressStatus;
use Inc\Enums\Course\StepType;
use Inc\Managers\Course\LessonManager;
use Inc\Managers\Wp\PostManager;
use Inc\Services\Course\LessonGateResolver;
use Inc\Services\Course\LessonPlayerService;
use Inc\Services\Course\LessonProgressService;
use PHPUnit\Framework\TestCase;

class LessonPlayerServiceTest extends TestCase {

	private LessonManager         $lessons;
	private LessonGateResolver    $gate;
	private LessonProgressService $progress;
	private PostManager           $posts;
	private LessonPlayerService   $service;

	protected function setUp(): void {
		parent::setUp();
		$this->lessons  = $this->createMock( LessonManager::class );
		$this->gate     = $this->createMock( LessonGateResolver::class );
		$this->progress = $this->createMock( LessonProgressService::class );
		$this->posts    = $this->createMock( PostManager::class );
		$this->service  = new LessonPlayerService( $this->lessons, $this->gate, $this->progress, $this->posts );
	}

	private function makeGroupLesson( int $id = 1, int $lessonId = 10, ?string $recordingUrl = null ): GroupLessonDTO {
		return new GroupLessonDTO(
			id               : $id,
			groupId          : 5,
			lessonId         : $lessonId,
			position         : 1,
			workIdsSnapshot  : null,
			extraWorkIds     : array(),
			scheduledAt      : null,
			endsAt           : null,
			isPinned         : false,
			teacherUserId    : null,
			visibility       : 'open',
			openedAt         : null,
			homeworkDueAt    : null,
			allowLate        : true,
			recordingUrl     : $recordingUrl,
			createdByUserId  : null,
			updatedByUserId  : null,
		);
	}

	private function makeLesson( int $id, array $steps ): LessonDTO {
		return new LessonDTO(
			id        : $id,
			subjectKey: 'inf',
			topic     : 'Урок',
			steps     : $steps,
			authorId  : 1,
			status    : 'publish',
		);
	}

	private function makeVideoStep( string $key, array $payload = array() ): StepDTO {
		return new StepDTO( $key, StepType::Video, $payload );
	}

	private function stubGateAndProgress( StepDTO ...$steps ): void {
		$this->gate->method( 'resolveStep' )->willReturn( GateState::Available );
		$this->progress->method( 'getStepStatuses' )->willReturn( array() );
	}

	// ── buildView ────────────────────────────────────────────────────────────

	public function test_build_view_returns_null_when_lesson_missing(): void {
		$this->lessons->method( 'get' )->willReturn( null );

		$result = $this->service->buildView( 1, $this->makeGroupLesson() );

		self::assertNull( $result );
	}

	public function test_build_view_returns_view_structure(): void {
		$step   = $this->makeVideoStep( 's1', array( 'url' => 'https://example.com/video' ) );
		$lesson = $this->makeLesson( 10, array( $step ) );
		$this->lessons->method( 'get' )->willReturn( $lesson );
		$this->stubGateAndProgress( $step );

		$view = $this->service->buildView( 1, $this->makeGroupLesson( lessonId: 10 ) );

		self::assertNotNull( $view );
		self::assertSame( 10, $view['lesson_id'] );
		self::assertCount( 1, $view['steps'] );
	}

	// ── recording slot (T1.5.13) ─────────────────────────────────────────────

	public function test_video_slot_uses_recording_url_when_available(): void {
		$step   = $this->makeVideoStep( 's1', array(
			'url'            => 'https://fallback.com/video',
			'recording_slot' => true,
		) );
		$lesson = $this->makeLesson( 10, array( $step ) );
		$this->lessons->method( 'get' )->willReturn( $lesson );
		$this->stubGateAndProgress( $step );

		$groupLesson = $this->makeGroupLesson( lessonId: 10, recordingUrl: 'https://s3.example.com/rec.mp4' );
		$view        = $this->service->buildView( 1, $groupLesson );

		$render = $view['steps'][0]['render'];
		self::assertSame( 'https://s3.example.com/rec.mp4', $render['url'] );
		self::assertTrue( $render['recording_slot'] );
	}

	public function test_video_slot_returns_empty_url_when_no_recording(): void {
		$step   = $this->makeVideoStep( 's1', array(
			'url'            => '',
			'recording_slot' => true,
		) );
		$lesson = $this->makeLesson( 10, array( $step ) );
		$this->lessons->method( 'get' )->willReturn( $lesson );
		$this->stubGateAndProgress( $step );

		$groupLesson = $this->makeGroupLesson( lessonId: 10, recordingUrl: null );
		$view        = $this->service->buildView( 1, $groupLesson );

		$render = $view['steps'][0]['render'];
		self::assertSame( '', $render['url'] );
		self::assertTrue( $render['recording_slot'] );
	}

	public function test_non_slot_video_ignores_recording_url(): void {
		$step   = $this->makeVideoStep( 's1', array(
			'url'            => 'https://youtube.com/watch?v=abc',
			'recording_slot' => false,
		) );
		$lesson = $this->makeLesson( 10, array( $step ) );
		$this->lessons->method( 'get' )->willReturn( $lesson );
		$this->stubGateAndProgress( $step );

		$groupLesson = $this->makeGroupLesson( lessonId: 10, recordingUrl: 'https://s3.example.com/rec.mp4' );
		$view        = $this->service->buildView( 1, $groupLesson );

		$render = $view['steps'][0]['render'];
		self::assertSame( 'https://youtube.com/watch?v=abc', $render['url'] );
		self::assertFalse( $render['recording_slot'] );
	}

	public function test_video_render_includes_provider_field(): void {
		$step   = $this->makeVideoStep( 's1', array(
			'url'      => 'https://vimeo.com/123',
			'provider' => 'vimeo',
		) );
		$lesson = $this->makeLesson( 10, array( $step ) );
		$this->lessons->method( 'get' )->willReturn( $lesson );
		$this->stubGateAndProgress( $step );

		$view   = $this->service->buildView( 1, $this->makeGroupLesson( lessonId: 10 ) );
		$render = $view['steps'][0]['render'];

		self::assertSame( 'vimeo', $render['provider'] );
	}

	public function test_video_slot_prefers_recording_url_over_payload_url(): void {
		$step   = $this->makeVideoStep( 's1', array(
			'url'            => 'https://payload.example.com/video',
			'recording_slot' => true,
		) );
		$lesson = $this->makeLesson( 10, array( $step ) );
		$this->lessons->method( 'get' )->willReturn( $lesson );
		$this->stubGateAndProgress( $step );

		$groupLesson = $this->makeGroupLesson( lessonId: 10, recordingUrl: 'https://s3.example.com/rec.mp4' );
		$view        = $this->service->buildView( 1, $groupLesson );

		// recordingUrl must win over payload url when slot is enabled.
		self::assertSame( 'https://s3.example.com/rec.mp4', $view['steps'][0]['render']['url'] );
	}
}
