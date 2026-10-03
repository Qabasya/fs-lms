<?php

declare( strict_types=1 );

namespace Unit\Services\Course;

use Inc\Contracts\TaskCheckerInterface;
use Inc\DTO\Course\GroupLessonDTO;
use Inc\DTO\Course\WorkDTO;
use Inc\DTO\Course\WorkTaskCheckDTO;
use Inc\DTO\Task\CheckResultDTO;
use Inc\Enums\Course\WorkType;
use Inc\Enums\Log\ErrorCode;
use Inc\Enums\Subject\TaskTemplate;
use Inc\Managers\Course\WorkManager;
use Inc\Managers\Wp\PostManager;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\WorkTaskCheckRepository;
use Inc\Services\Course\EffectiveWorksResolver;
use Inc\Services\Course\LessonAccessPolicy;
use Inc\Services\Course\SubmissionService;
use Inc\Services\Course\WorkTaskCheckService;
use Inc\Services\Task\TaskCheckerRegistry;
use Inc\Services\Template\TemplateResolver;
use Inc\Shared\CodedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Проверка ответа кнопкой внутри работы: гейты, лимит в 3 проверки, запись, состояние для плеера.
 */
class WorkTaskCheckServiceTest extends TestCase {

	private WorkTaskCheckRepository $checks;
	private SubmissionService       $submissions;
	private LessonAccessPolicy      $policy;
	private GroupLessonRepository   $groupLessons;
	private EffectiveWorksResolver  $resolver;
	private WorkManager             $works;
	private PostManager             $posts;
	private TemplateResolver        $templates;
	private TaskCheckerRegistry     $registry;
	private WorkTaskCheckService    $service;

	private TaskTemplate $template = TaskTemplate::Standard;
	private int $maxAttempts        = 0;

	protected function setUp(): void {
		parent::setUp();

		$this->checks       = $this->createMock( WorkTaskCheckRepository::class );
		$this->submissions  = $this->createMock( SubmissionService::class );
		$this->policy       = $this->createMock( LessonAccessPolicy::class );
		$this->groupLessons = $this->createMock( GroupLessonRepository::class );
		$this->resolver     = $this->createMock( EffectiveWorksResolver::class );
		$this->works        = $this->createMock( WorkManager::class );
		$this->posts        = $this->createMock( PostManager::class );
		$this->templates    = $this->createMock( TemplateResolver::class );
		$this->registry     = $this->createMock( TaskCheckerRegistry::class );

		$this->service = new WorkTaskCheckService(
			$this->checks, $this->submissions, $this->policy, $this->groupLessons,
			$this->resolver, $this->works, $this->posts, $this->templates, $this->registry
		);
	}

	private function work( int $id ): WorkDTO {
		return new WorkDTO(
			id: $id, subjectKey: 'inf', title: 'W', workType: WorkType::Practice,
			itemIds: array( 71, 72 ), instructions: '', authorId: 1, status: 'publish',
			maxAttempts: $this->maxAttempts,
		);
	}

	/** Успешный путь: ученик допущен, работа и задание на месте, чекер отвечает $correct. */
	private function arrange( bool $correct, array $lockedTaskIds = array(), int $attemptsUsed = 0 ): void {
		$this->policy->method( 'canSubmit' )->willReturn( true );
		$this->groupLessons->method( 'find' )->willReturn(
			GroupLessonDTO::fromArray( array( 'id' => 5, 'group_id' => 1, 'position' => 1 ) )
		);
		$work = $this->work( 55 );
		$this->resolver->method( 'resolve' )->willReturn( array( $work ) );
		$this->works->method( 'get' )->willReturn( $work );
		$this->submissions->method( 'workAttemptsUsed' )->willReturn( $attemptsUsed );
		$this->submissions->method( 'lockedTaskIds' )->willReturn( $lockedTaskIds );
		$this->posts->method( 'get' )->willReturn( new \WP_Post( array( 'ID' => 71 ) ) );
		$this->posts->method( 'getMeta' )->willReturn( array( 'task_answer' => 'a' ) );
		$this->templates->method( 'resolveEnum' )->willReturnCallback( fn() => $this->template );

		$checker = $this->createMock( TaskCheckerInterface::class );
		$checker->method( 'check' )->willReturn( new CheckResultDTO( $correct, $correct ? 1.0 : 0.0, 1.0, array() ) );
		$this->registry->method( 'get' )->willReturn( $checker );
	}

	private function check( mixed $answer = 'a' ): array {
		return $this->service->check( 10, 5, 55, 71, $answer );
	}

	private function expectCode( ErrorCode $code ): void {
		try {
			$this->check();
			self::fail( 'Ожидалось CodedException ' . $code->value );
		} catch ( CodedException $e ) {
			self::assertSame( $code, $e->errorCode );
		}
	}

	public function test_correct_check_is_recorded_without_leaking_the_reference(): void {
		$this->arrange( true );
		$this->checks->method( 'listByRound' )->willReturn( array() );
		$this->checks->expects( $this->once() )->method( 'create' )->with( 10, 5, 55, 71, 1, 'a', true );

		self::assertSame(
			array( 'is_correct' => true, 'checks_used' => 1, 'checks_max' => 3 ),
			$this->check()
		);
	}

	public function test_check_round_follows_submitted_attempts(): void {
		$this->arrange( false, array(), 2 );
		$this->checks->expects( $this->once() )->method( 'listByRound' )->with( 10, 5, 55, 3 )->willReturn( array() );
		$this->checks->expects( $this->once() )->method( 'create' )->with( 10, 5, 55, 71, 3, 'a', false );

		self::assertFalse( $this->check()['is_correct'] );
	}

	public function test_fourth_check_is_refused(): void {
		$this->arrange( true );
		$this->checks->method( 'listByRound' )->willReturn( array(
			new WorkTaskCheckDTO( 1, 71, 1, 'x', false ),
			new WorkTaskCheckDTO( 2, 71, 1, 'y', false ),
			new WorkTaskCheckDTO( 3, 71, 1, 'z', false ),
		) );
		$this->checks->expects( $this->never() )->method( 'create' );

		$this->expectCode( ErrorCode::WorkCheckLimit );
	}

	/** Лимит считается по задаче: проверки соседней задачи места не занимают. */
	public function test_other_task_checks_do_not_count(): void {
		$this->arrange( true );
		$this->checks->method( 'listByRound' )->willReturn( array(
			new WorkTaskCheckDTO( 1, 72, 1, 'x', false ),
			new WorkTaskCheckDTO( 2, 72, 1, 'y', false ),
			new WorkTaskCheckDTO( 3, 72, 1, 'z', false ),
		) );

		self::assertSame( 1, $this->check()['checks_used'] );
	}

	public function test_already_solved_task_is_closed(): void {
		$this->arrange( true );
		$this->checks->method( 'listByRound' )->willReturn( array( new WorkTaskCheckDTO( 1, 71, 1, 'a', true ) ) );
		$this->checks->expects( $this->never() )->method( 'create' );

		$this->expectCode( ErrorCode::WorkCheckKind );
	}

	public function test_credited_task_from_previous_round_is_closed(): void {
		$this->arrange( true, array( 71 ), 1 );
		$this->checks->expects( $this->never() )->method( 'create' );

		$this->expectCode( ErrorCode::WorkCheckKind );
	}

	/** @return array<string, array{TaskTemplate}> */
	public static function noButtonTemplates(): array {
		return array(
			'выбор'         => array( TaskTemplate::Choice ),
			'сопоставление' => array( TaskTemplate::Matching ),
			'сортировка'    => array( TaskTemplate::Ordering ),
			'развёрнутый'   => array( TaskTemplate::FileAnswer ),
			'робо'          => array( TaskTemplate::Robo ),
		);
	}

	#[DataProvider( 'noButtonTemplates' )]
	public function test_templates_without_button_are_refused( TaskTemplate $template ): void {
		$this->template = $template;
		$this->arrange( true );
		$this->checks->method( 'listByRound' )->willReturn( array() );
		$this->checks->expects( $this->never() )->method( 'create' );

		$this->expectCode( ErrorCode::WorkCheckKind );
	}

	public function test_text_templates_allow_the_button(): void {
		foreach ( array( TaskTemplate::Standard, TaskTemplate::Code, TaskTemplate::Triple, TaskTemplate::Fill, TaskTemplate::Audio ) as $template ) {
			self::assertTrue( $template->allowsInlineCheck(), $template->value );
		}
	}

	public function test_exhausted_work_attempts_block_the_check(): void {
		$this->maxAttempts = 2;
		$this->arrange( true, array(), 2 );
		$this->checks->expects( $this->never() )->method( 'create' );

		$this->expectCode( ErrorCode::WorkLimit );
	}

	public function test_task_outside_the_work_is_refused(): void {
		$this->arrange( true );

		$this->expectException( CodedException::class );
		$this->service->check( 10, 5, 55, 999, 'a' );
	}

	public function test_student_without_access_is_refused(): void {
		$this->policy->method( 'canSubmit' )->willReturn( false );
		$this->checks->expects( $this->never() )->method( 'create' );

		$this->expectCode( ErrorCode::WorkAccess );
	}

	public function test_state_reports_status_used_and_last_answer(): void {
		$this->submissions->method( 'workAttemptsUsed' )->willReturn( 0 );
		$this->checks->method( 'listByRound' )->willReturn( array(
			new WorkTaskCheckDTO( 1, 71, 1, 'x', false ),
			new WorkTaskCheckDTO( 2, 71, 1, 'a', true ),
			new WorkTaskCheckDTO( 3, 72, 1, 'q', false ),
		) );

		self::assertSame(
			array(
				71 => array( 'status' => 'correct', 'used' => 2, 'answer' => 'a' ),
				72 => array( 'status' => 'wrong', 'used' => 1, 'answer' => 'q' ),
			),
			$this->service->state( 10, 5, 55 )
		);
	}

	public function test_state_for_teacher_without_person_is_empty(): void {
		$this->checks->expects( $this->never() )->method( 'listByRound' );

		self::assertSame( array(), $this->service->state( 0, 5, 55 ) );
	}
}
