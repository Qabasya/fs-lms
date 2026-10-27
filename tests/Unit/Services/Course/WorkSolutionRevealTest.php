<?php

declare( strict_types=1 );

namespace Unit\Services\Course;

use Inc\DTO\Course\WorkDTO;
use Inc\Enums\Course\WorkType;
use Inc\Managers\Wp\PostManager;
use Inc\Services\Course\WorkSolutionReveal;
use Inc\Services\Task\CorrectAnswerResolver;
use Inc\Services\Task\TaskSolutionService;
use PHPUnit\Framework\TestCase;

/**
 * Решение вопросов «Опроса» ученику: после верного ответа или когда попытки исчерпаны.
 */
class WorkSolutionRevealTest extends TestCase {

	private WorkSolutionReveal $reveal;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_posts();
		$resolver = $this->createMock( CorrectAnswerResolver::class );
		$resolver->method( 'resolve' )->willReturn( null );

		$this->reveal = new WorkSolutionReveal( new TaskSolutionService( $resolver ), new PostManager() );

		fs_test_seed_post( array( 'ID' => 1, 'post_type' => 'inf_tasks' ), array( 'fs_lms_meta' => array( 'task_text' => '<p>Разбор первого</p>' ) ) );
		fs_test_seed_post( array( 'ID' => 2, 'post_type' => 'inf_tasks' ), array( 'fs_lms_meta' => array( 'task_text' => '<p>Разбор второго</p>' ) ) );
		fs_test_seed_post( array( 'ID' => 3, 'post_type' => 'inf_tasks' ), array( 'fs_lms_meta' => array( 'task_text' => '' ) ) ); // решения нет
	}

	private function work( WorkType $type = WorkType::Survey, int $maxAttempts = 0 ): WorkDTO {
		return new WorkDTO(
			id          : 10,
			subjectKey  : 'inf',
			title       : 'Опрос',
			workType    : $type,
			itemIds     : array( 1, 2, 3 ),
			instructions: '',
			authorId    : 1,
			status      : 'publish',
			maxAttempts : $maxAttempts,
		);
	}

	public function test_correct_answer_reveals_only_that_tasks_solution(): void {
		$verdicts = array(
			1 => array( 'verdict' => 'correct' ),
			2 => array( 'verdict' => 'incorrect' ),
		);

		$solutions = $this->reveal->forStudent( $this->work(), $verdicts, 1 );

		self::assertSame( array( 1 ), array_keys( $solutions ) );
		self::assertStringContainsString( 'Разбор первого', $solutions[1] );
	}

	public function test_exhausted_attempts_reveal_solutions_of_wrong_answers_too(): void {
		$verdicts = array(
			1 => array( 'verdict' => 'incorrect' ),
			2 => array( 'verdict' => 'incorrect' ),
		);

		$solutions = $this->reveal->forStudent( $this->work( WorkType::Survey, 2 ), $verdicts, 2 );

		self::assertSame( array( 1, 2 ), array_keys( $solutions ) );
	}

	public function test_attempts_left_keep_wrong_answers_hidden(): void {
		$verdicts = array( 1 => array( 'verdict' => 'incorrect' ) );

		self::assertSame( array(), $this->reveal->forStudent( $this->work( WorkType::Survey, 3 ), $verdicts, 1 ) );
	}

	/** Без лимита попыток «исчерпания» не бывает: открывает решение только верный ответ. */
	public function test_unlimited_attempts_never_count_as_exhausted(): void {
		$verdicts = array( 1 => array( 'verdict' => 'incorrect' ) );

		self::assertSame( array(), $this->reveal->forStudent( $this->work( WorkType::Survey, 0 ), $verdicts, 50 ) );
	}

	public function test_task_without_solution_text_is_skipped(): void {
		$verdicts = array( 3 => array( 'verdict' => 'correct' ) );

		self::assertSame( array(), $this->reveal->forStudent( $this->work(), $verdicts, 1 ) );
	}

	/** Другие типы работ решение ученику не показывают (это правило опроса). */
	public function test_other_work_types_reveal_nothing(): void {
		$verdicts = array( 1 => array( 'verdict' => 'correct' ) );

		self::assertSame( array(), $this->reveal->forStudent( $this->work( WorkType::Homework, 1 ), $verdicts, 1 ) );
	}

	public function test_no_submission_reveals_nothing(): void {
		self::assertSame( array(), $this->reveal->forStudent( $this->work( WorkType::Survey, 1 ), array(), 0 ) );
	}
}
