<?php

declare( strict_types=1 );

namespace Unit\Callbacks\Course;

use Inc\Callbacks\Course\SubmitTaskAnswerCallbacks;
use Inc\DTO\Course\GroupLessonDTO;
use Inc\DTO\Person\PersonDTO;
use Inc\DTO\Task\CheckResultDTO;
use Inc\DTO\Task\TaskAttemptDTO;
use Inc\Contracts\TaskCheckerInterface;
use Inc\Enums\Subject\TaskTemplate;
use Inc\Enums\Wp\PostMetaName;
use Inc\DTO\Course\StepSettingsDTO;
use Inc\Managers\Wp\PostManager;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Repositories\WPDBRepositories\TaskAttemptRepository;
use Inc\Services\Course\EffectiveStepSettingsResolver;
use Inc\Services\Course\LessonProgressService;
use Inc\Services\Task\CorrectAnswerResolver;
use Inc\Services\Task\TaskCheckerRegistry;
use Inc\Services\Template\TemplateResolver;
use PHPUnit\Framework\TestCase;

/**
 * Тесты сдачи ответа на task-шаг. Фокус — D20 (T14.8): эталон отдаётся
 * ТОЛЬКО при исчерпании попыток с провалом, никогда при живых попытках
 * или верном ответе.
 */
class SubmitTaskAnswerCallbacksTest extends TestCase {

	private PersonRepository              $persons;
	private GroupLessonRepository         $groupLessons;
	private PostManager                   $posts;
	private TaskCheckerRegistry           $checkers;
	private TaskAttemptRepository         $taskAttempts;
	private LessonProgressService         $progress;
	private TemplateResolver              $resolver;
	private EffectiveStepSettingsResolver $settingsResolver;
	private CorrectAnswerResolver         $correctAnswers;
	private SubmitTaskAnswerCallbacks     $cb;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_ajax();
		$GLOBALS['_fs_test_user_id'] = 7;

		$this->persons          = $this->createMock( PersonRepository::class );
		$this->groupLessons     = $this->createMock( GroupLessonRepository::class );
		$this->posts            = $this->createMock( PostManager::class );
		$this->checkers         = $this->createMock( TaskCheckerRegistry::class );
		$this->taskAttempts     = $this->createMock( TaskAttemptRepository::class );
		$this->progress         = $this->createMock( LessonProgressService::class );
		$this->resolver         = $this->createMock( TemplateResolver::class );
		$this->settingsResolver = $this->createMock( EffectiveStepSettingsResolver::class );
		$this->correctAnswers   = $this->createMock( CorrectAnswerResolver::class );

		$this->cb = new SubmitTaskAnswerCallbacks(
			$this->persons,
			$this->groupLessons,
			$this->posts,
			$this->checkers,
			$this->taskAttempts,
			$this->progress,
			$this->resolver,
			$this->settingsResolver,
			$this->correctAnswers
		);
	}

	/**
	 * Общий happy-path контекст: ученик 99, занятие 5 (урок 10),
	 * task-шаг s1 → задача 77 (choice), лимит попыток $maxAttempts.
	 *
	 * @param TaskAttemptDTO[] $priorAttempts
	 */
	private function arrange( int $maxAttempts, array $priorAttempts, CheckResultDTO $result ): void {
		$this->persons->method( 'findByWpUserId' )->with( 7 )
			->willReturn( PersonDTO::fromArray( array( 'id' => 99, 'created_at' => '2024-01-01 00:00:00', 'updated_at' => '2024-01-01 00:00:00' ) ) );

		$this->groupLessons->method( 'find' )->with( 5 )->willReturn(
			GroupLessonDTO::fromArray( array( 'id' => 5, 'group_id' => 1, 'lesson_id' => 10, 'position' => 0 ) )
		);

		$this->posts->method( 'getMeta' )->willReturnCallback(
			static fn( int $postId, string $key ) => match ( true ) {
				10 === $postId && PostMetaName::Meta->value === $key => array(
					'steps' => array(
						array( 'key' => 's1', 'type' => 'task', 'payload' => array( 'ref' => 77 ) ),
					),
				),
				77 === $postId && PostMetaName::Meta->value === $key => array( 'task_options' => array() ),
				default => null,
			}
		);
		$this->posts->method( 'get' )->with( 77 )->willReturn( new \WP_Post( array( 'ID' => 77 ) ) );

		$this->resolver->method( 'resolveEnum' )->willReturn( TaskTemplate::Choice );

		$checker = $this->createMock( TaskCheckerInterface::class );
		$checker->method( 'check' )->willReturn( $result );
		$this->checkers->method( 'get' )->willReturn( $checker );

		$this->settingsResolver->method( 'resolve' )->willReturn( new StepSettingsDTO( maxAttempts: $maxAttempts ) );
		$this->taskAttempts->method( 'listByStep' )->with( 99, 5, 's1' )->willReturn( $priorAttempts );

		$_POST = array(
			'group_lesson_id' => '5',
			'step_key'        => 's1',
			'answer'          => '["o1"]',
		);
	}

	private function wrongAttempt( int $number ): TaskAttemptDTO {
		return TaskAttemptDTO::fromArray( array(
			'id'                => $number,
			'student_person_id' => 99,
			'group_lesson_id'   => 5,
			'step_key'          => 's1',
			'task_id'           => 77,
			'attempt_number'    => $number,
			'is_correct'        => 0,
			'created_at'        => '2026-01-01 00:00:00',
		) );
	}

	public function test_no_correct_answer_while_attempts_remain(): void {
		// 1-я попытка из 3 — неверно: эталон НЕ отдаётся, шаг остаётся available.
		$this->arrange( 3, array(), CheckResultDTO::incorrect( 2.0 ) );
		$this->correctAnswers->expects( $this->never() )->method( 'resolve' );
		$this->progress->expects( $this->never() )->method( 'markFailed' );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxSubmitTaskAnswer() );

		self::assertTrue( $r->success );
		self::assertFalse( $r->payload['is_correct'] );
		self::assertSame( 'available', $r->payload['step_status'] );
		self::assertArrayNotHasKey( 'correct_answer', $r->payload );
		self::assertArrayNotHasKey( 'correct_answer_ids', $r->payload );
	}

	public function test_correct_answer_revealed_on_exhaustion(): void {
		// 2-я попытка из 2 — неверно: шаг failed, эталон и id верных опций в ответе.
		$this->arrange( 2, array( $this->wrongAttempt( 1 ) ), CheckResultDTO::incorrect( 2.0 ) );
		$this->correctAnswers->method( 'resolve' )->with( 77 )->willReturn( 'Вариант Б' );
		$this->correctAnswers->method( 'choiceCorrectIds' )->with( 77 )->willReturn( array( 'o2' ) );
		$this->progress->expects( $this->once() )->method( 'markFailed' )->with( 99, 5, 's1' );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxSubmitTaskAnswer() );

		self::assertTrue( $r->success );
		self::assertSame( 'failed', $r->payload['step_status'] );
		self::assertSame( 'Вариант Б', $r->payload['correct_answer'] );
		self::assertSame( array( 'o2' ), $r->payload['correct_answer_ids'] );
	}

	public function test_no_correct_answer_on_success_even_last_attempt(): void {
		// Последняя попытка, но ответ верный: completed, эталон не нужен и не отдаётся.
		$this->arrange( 2, array( $this->wrongAttempt( 1 ) ), CheckResultDTO::correct( 2.0 ) );
		$this->correctAnswers->expects( $this->never() )->method( 'resolve' );
		$this->progress->expects( $this->once() )->method( 'markCompleted' )->with( 99, 5, 's1' );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxSubmitTaskAnswer() );

		self::assertTrue( $r->success );
		self::assertSame( 'completed', $r->payload['step_status'] );
		self::assertArrayNotHasKey( 'correct_answer', $r->payload );
	}

	public function test_unlimited_attempts_never_reveal(): void {
		// maxAttempts=0 (безлимит): сколько бы ни ошибался — эталона нет.
		$this->arrange( 0, array( $this->wrongAttempt( 1 ), $this->wrongAttempt( 2 ) ), CheckResultDTO::incorrect( 2.0 ) );
		$this->correctAnswers->expects( $this->never() )->method( 'resolve' );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxSubmitTaskAnswer() );

		self::assertTrue( $r->success );
		self::assertSame( 'available', $r->payload['step_status'] );
		self::assertArrayNotHasKey( 'correct_answer', $r->payload );
	}

	public function test_guest_is_rejected(): void {
		unset( $GLOBALS['_fs_test_user_id'] );
		$_POST = array( 'group_lesson_id' => '5', 'step_key' => 's1', 'answer' => '[]' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxSubmitTaskAnswer() )->success );
	}
}
