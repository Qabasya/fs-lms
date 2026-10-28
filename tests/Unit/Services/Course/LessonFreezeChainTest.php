<?php

declare( strict_types=1 );

namespace Unit\Services\Course;

use Inc\Contracts\ClockInterface;
use Inc\DTO\Course\GroupLessonDTO;
use Inc\DTO\Course\LessonDTO;
use Inc\DTO\Enrollment\StudentRecordDTO;
use Inc\Enums\Access\AccessLevel;
use Inc\Enums\Course\GateState;
use Inc\Managers\Course\LessonManager;
use Inc\Repositories\OptionsRepositories\ExpulsionPolicyRepository;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Assessment\ExamLockService;
use Inc\Services\Course\LessonAccessPolicy;
use Inc\Services\Course\LessonGateResolver;
use Inc\Services\Course\LessonProgressService;
use Inc\Services\Course\LessonVisibilityService;
use Inc\Services\Course\StepContentRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Цепочка «шов доступа → политика → гейт шага» на настоящих объектах (репозитории — моки).
 *
 * `SubmitTaskAnswerCallbacks` и `LessonPlayerCallbacks::ajaxMarkStepProgress()` пускают ответ и прогресс только при
 * `LessonGateResolver::resolveStep()->isAvailable()`; их тесты проверяют отказ при закрытом гейте. Здесь закреплено
 * недостающее звено: понижение уровня доступа подписчиком шва `fs_lms_lesson_access` закрывает гейт — значит, закрывает и
 * ответы с открытой вкладки, а не только страницу урока.
 */
class LessonFreezeChainTest extends TestCase {

	private LessonGateResolver $gate;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_test_options'] = array();

		$record = StudentRecordDTO::fromArray( array(
			'id'                  => 1,
			'student_person_id'   => 5,
			'parent_person_id'    => 0,
			'group_id'            => 10,
			'snapshot_last_name'  => 'T',
			'snapshot_first_name' => 'U',
			'status'              => 'active',
			'enrolled_at'         => '2024-01-01 00:00:00',
			'expelled_at'         => null,
			'created_at'          => '2024-01-01 00:00:00',
			'updated_at'          => '2024-01-01 00:00:00',
		) );

		$records = $this->createStub( StudentRecordRepository::class );
		$records->method( 'findAllByStudentAndGroup' )->willReturn( array( $record ) );
		$lessonsRepo = $this->createStub( GroupLessonRepository::class );
		$lessonsRepo->method( 'find' )->willReturn( $this->groupLesson( '2024-03-01 09:00:00' ) );
		$visibility = $this->createStub( LessonVisibilityService::class );
		$visibility->method( 'effectiveVisibility' )->willReturn( 'open' );

		$policy = new LessonAccessPolicy( $records, $lessonsRepo, new ExpulsionPolicyRepository(), $visibility, $this->createStub( GroupsRepository::class ) );

		$bank = $this->createStub( LessonManager::class );
		$bank->method( 'get' )->willReturn( LessonDTO::fromArray( array(
			'id'          => 7,
			'subject_key' => 'inf',
			'topic'       => 'L',
			'author_id'   => 0,
			'status'      => 'publish',
			'steps'       => array( array( 'key' => 's1', 'type' => 'task', 'payload' => array() ) ),
		) ) );
		$progress = $this->createStub( LessonProgressService::class );
		$progress->method( 'getStepStatuses' )->willReturn( array() );
		$exam = $this->createStub( ExamLockService::class );
		$exam->method( 'isLocked' )->willReturn( false );
		$clock = $this->createStub( ClockInterface::class );
		$clock->method( 'now' )->willReturn( '2024-06-01 00:00:00' );

		$this->gate = new LessonGateResolver( $progress, $bank, $policy, $exam, $clock, $this->createStub( StepContentRenderer::class ) );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_fs_test_filter_returns'] );
		parent::tearDown();
	}

	private function groupLesson( string $scheduledAt ): GroupLessonDTO {
		return GroupLessonDTO::fromArray( array(
			'id'           => 42,
			'group_id'     => 10,
			'lesson_id'    => 7,
			'position'     => 0,
			'scheduled_at' => $scheduledAt,
			'visibility'   => 'open',
		) );
	}

	public function test_step_is_available_without_a_subscriber(): void {
		self::assertSame( GateState::Available, $this->gate->resolveStep( 5, $this->groupLesson( '2024-03-01 09:00:00' ), 's1' ) );
	}

	public function test_lowering_to_none_locks_the_step_so_answers_and_progress_are_refused(): void {
		$GLOBALS['_fs_test_filter_returns']['fs_lms_lesson_access'] = AccessLevel::None;
		$lesson = $this->groupLesson( '2024-03-01 09:00:00' );

		self::assertSame( GateState::Locked, $this->gate->resolveLesson( 5, $lesson ) );
		self::assertSame( GateState::Locked, $this->gate->resolveStep( 5, $lesson, 's1' ) );
	}

	public function test_a_subscriber_cannot_unlock_what_the_date_gate_keeps_closed(): void {
		$GLOBALS['_fs_test_filter_returns']['fs_lms_lesson_access'] = AccessLevel::ReadSubmit;

		// Дата занятия не наступила: урок закрыт гейтом даты, подписчик шва на это повлиять не может.
		self::assertSame( GateState::Locked, $this->gate->resolveLesson( 5, $this->groupLesson( '2099-01-01 09:00:00' ) ) );
	}
}
