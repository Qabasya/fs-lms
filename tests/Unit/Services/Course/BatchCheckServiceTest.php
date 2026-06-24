<?php

declare( strict_types=1 );

namespace Unit\Services\Course;

use Inc\DTO\Course\BatchCheckResultDTO;
use Inc\Enums\Assessment\AssessmentKind;
use Inc\Enums\Subject\TaskTemplate as TT;
use Inc\Enums\Wp\PostMetaName;
use Inc\Managers\Wp\PostManager;
use Inc\MetaBoxes\Templates\BaseTemplate;
use Inc\Services\Course\BatchCheckService;
use Inc\Services\Task\Checkers\ChoiceChecker;
use Inc\Services\Task\Checkers\TextAnswerChecker;
use Inc\Services\Task\TaskCheckerRegistry;
use Inc\Services\Task\Checkers\FillChecker;
use Inc\Services\Task\Checkers\MatchingChecker;
use Inc\Services\Task\Checkers\OrderingChecker;
use Inc\Services\Task\Checkers\TripleAnswerChecker;
use Inc\Services\Template\TemplateRegistry;
use Inc\Services\Template\TemplateResolver;
use PHPUnit\Framework\TestCase;
use WP_Post;

/**
 * @covers \Inc\Services\Course\BatchCheckService
 */
class BatchCheckServiceTest extends TestCase {

	private PostManager       $posts;
	private TemplateResolver  $resolver;
	private TemplateRegistry  $templates;
	private TaskCheckerRegistry $checkers;
	private BatchCheckService $svc;

	protected function setUp(): void {
		fs_test_reset_posts();
		$this->posts     = $this->createMock( PostManager::class );
		$this->resolver  = $this->createMock( TemplateResolver::class );
		$this->templates = $this->createMock( TemplateRegistry::class );
		$this->checkers  = new TaskCheckerRegistry(
			new TextAnswerChecker(),
			new TripleAnswerChecker(),
			new ChoiceChecker(),
			new MatchingChecker(),
			new OrderingChecker(),
			new FillChecker(),
		);
		$this->svc = new BatchCheckService(
			$this->posts,
			$this->resolver,
			$this->templates,
			$this->checkers,
		);
	}

	private function fakePost( int $id ): WP_Post {
		$post = new WP_Post( [ 'ID' => $id, 'post_type' => 'inf_tasks' ] );
		$this->posts->method( 'get' )->with( $id )->willReturn( $post );
		return $post;
	}

public function test_empty_answers_returns_zero_counts(): void {
		$result = $this->svc->check( [] );

		self::assertInstanceOf( BatchCheckResultDTO::class, $result );
		self::assertSame( 0, $result->correctCount );
		self::assertSame( 0, $result->totalCount );
		self::assertSame( 0.0, $result->weightedScore );
	}

	public function test_correct_choice_answer(): void {
		$post    = new WP_Post( [ 'ID' => 1, 'post_type' => 'inf_tasks' ] );
		$options = [
			[ 'id' => '0', 'text' => 'A', 'correct' => true ],
			[ 'id' => '1', 'text' => 'B', 'is_correct' => false ],
		];

		$this->posts->method( 'get' )->willReturn( $post );
		$this->resolver->method( 'resolveId' )->willReturn( 'choice' );
		$this->resolver->method( 'resolveEnum' )->willReturn( TT::Choice );
		$this->templates->method( 'get' )->willReturn( null );
		$this->posts->method( 'getMeta' )->willReturn( [
			'task_options' => [ 'multiple' => false, 'options' => $options ],
		] );

		$result = $this->svc->check( [ 1 => [ '0' ] ] );

		self::assertSame( 1, $result->correctCount );
		self::assertSame( 1, $result->totalCount );
		self::assertSame( 1.0, $result->weightedScore );
		self::assertFalse( $result->hasManual );
		self::assertSame( 'correct', $result->perTask[1]['verdict'] );
	}

	public function test_wrong_choice_answer(): void {
		$post    = new WP_Post( [ 'ID' => 2, 'post_type' => 'inf_tasks' ] );
		$options = [
			[ 'id' => '0', 'text' => 'A', 'correct' => true ],
			[ 'id' => '1', 'text' => 'B', 'is_correct' => false ],
		];
		$this->posts->method( 'get' )->willReturn( $post );
		$this->resolver->method( 'resolveId' )->willReturn( 'choice' );
		$this->resolver->method( 'resolveEnum' )->willReturn( TT::Choice );
		$this->templates->method( 'get' )->willReturn( null );
		$this->posts->method( 'getMeta' )->willReturn( [
			'task_options' => [ 'multiple' => false, 'options' => $options ],
		] );

		$result = $this->svc->check( [ 2 => [ '1' ] ] );

		self::assertSame( 0, $result->correctCount );
		self::assertSame( 'incorrect', $result->perTask[2]['verdict'] );
	}

	public function test_unknown_task_id_marked_pending(): void {
		$this->posts->method( 'get' )->willReturn( null );

		$result = $this->svc->check( [ 99 => 'some_answer' ] );

		self::assertTrue( $result->hasManual );
		self::assertSame( 'pending', $result->perTask[99]['verdict'] );
	}

	public function test_manual_task_type_marked_pending(): void {
		$post = new WP_Post( [ 'ID' => 3, 'post_type' => 'inf_tasks' ] );
		$this->posts->method( 'get' )->willReturn( $post );
		$this->resolver->method( 'resolveId' )->willReturn( 'code' );
		$this->resolver->method( 'resolveEnum' )->willReturn( TT::Code );
		$this->templates->method( 'get' )->willReturn( null );
		$this->posts->method( 'getMeta' )->willReturn( [] );

		$result = $this->svc->check( [ 3 => 'some code' ] );

		self::assertTrue( $result->hasManual );
		self::assertSame( 'pending', $result->perTask[3]['verdict'] );
	}

	public function test_custom_weight_applied_for_correct_answer(): void {
		$post    = new WP_Post( [ 'ID' => 4, 'post_type' => 'inf_tasks' ] );
		$options = [
			[ 'id' => '0', 'text' => 'A', 'correct' => true ],
			[ 'id' => '1', 'text' => 'B', 'is_correct' => false ],
		];
		$this->posts->method( 'get' )->willReturn( $post );
		$this->resolver->method( 'resolveId' )->willReturn( 'choice' );
		$this->resolver->method( 'resolveEnum' )->willReturn( TT::Choice );
		$this->templates->method( 'get' )->willReturn( null );
		$this->posts->method( 'getMeta' )->willReturn( [
			'task_options' => [ 'multiple' => false, 'options' => $options ],
		] );

		$result = $this->svc->check( [ 4 => [ '0' ] ], [ 4 => 3.0 ] );

		self::assertSame( 3.0, $result->weightedScore );
		self::assertSame( 3.0, $result->maxWeightedScore );
	}

	public function test_tally_format(): void {
		$result = new BatchCheckResultDTO(
			perTask          : [],
			correctCount     : 5,
			totalCount       : 8,
			weightedScore    : 0.0,
			maxWeightedScore : 0.0,
			hasManual        : false,
		);
		self::assertSame( '5/8', $result->tally() );
	}

	public function test_threeinone_expansion_in_ege_mode(): void {
		$post = new WP_Post( [ 'ID' => 10, 'post_type' => 'inf_tasks' ] );
		$this->posts->method( 'get' )->willReturn( $post );
		$this->resolver->method( 'resolveId' )->willReturn( 'three_in_one' );
		$this->resolver->method( 'resolveEnum' )->willReturn( TT::Triple );

		// Stub template object that expands for exam.
		$templateObj = $this->createMock( BaseTemplate::class );
		$templateObj->method( 'expandsForExam' )->willReturn( [
			[ 'key' => '19', 'condition_field' => 'task_19_condition', 'answer_field' => 'task_19_answer' ],
			[ 'key' => '20', 'condition_field' => 'task_20_condition', 'answer_field' => 'task_20_answer' ],
			[ 'key' => '21', 'condition_field' => 'task_21_condition', 'answer_field' => 'task_21_answer' ],
		] );

		$this->templates->method( 'get' )->willReturn( $templateObj );
		$this->posts->method( 'getMeta' )->willReturn( [
			'task_19_answer' => '42',
			'task_20_answer' => 'нет',
			'task_21_answer' => '7',
		] );

		$studentAnswers = [ '19' => '42', '20' => 'ДА', '21' => '7' ];
		$result         = $this->svc->check(
			[ 10 => $studentAnswers ],
			[ '10:19' => 2.0, '10:20' => 1.0, '10:21' => 1.0 ],
			AssessmentKind::Ege,
		);

		// Task 19: correct (42=42), 20: wrong (нет≠ДА), 21: correct (7=7).
		self::assertSame( 2, $result->correctCount );
		self::assertSame( 3, $result->totalCount );
		self::assertSame( 3.0, $result->weightedScore ); // 2.0 + 0 + 1.0
		self::assertSame( 'correct',   $result->perTask['10:19']['verdict'] );
		self::assertSame( 'incorrect', $result->perTask['10:20']['verdict'] );
		self::assertSame( 'correct',   $result->perTask['10:21']['verdict'] );
	}

	public function test_no_expansion_without_ege_kind(): void {
		$post = new WP_Post( [ 'ID' => 11, 'post_type' => 'inf_tasks' ] );
		$this->posts->method( 'get' )->willReturn( $post );
		$this->resolver->method( 'resolveId' )->willReturn( 'three_in_one' );
		$this->resolver->method( 'resolveEnum' )->willReturn( TT::Triple );

		$templateObj = $this->createMock( BaseTemplate::class );
		// expandsForExam must NOT be called when kind is null.
		$templateObj->expects( $this->never() )->method( 'expandsForExam' );

		$this->templates->method( 'get' )->willReturn( $templateObj );
		$this->posts->method( 'getMeta' )->willReturn( [
			'task_19_answer' => '42',
			'task_20_answer' => 'нет',
			'task_21_answer' => '7',
		] );

		// kind=null → no expansion → TripleAnswerChecker used as a single unit.
		// No per-sub-key entries in perTask — only the whole task ID 11.
		$result = $this->svc->check( [ 11 => [ '19' => '42', '20' => 'нет', '21' => '7' ] ], [], null );

		self::assertFalse( $result->hasManual );
		self::assertArrayHasKey( 11, $result->perTask );
		self::assertArrayNotHasKey( '11:19', $result->perTask );
		self::assertSame( 1, $result->correctCount ); // 3/3 → isCorrect=true in partial
	}
}
