<?php

declare( strict_types=1 );

namespace Unit\Callbacks\Course;

use Inc\Callbacks\Course\PreviewSolveCallbacks;
use Inc\Contracts\TaskCheckerInterface;
use Inc\DTO\Assessment\AssessmentDTO;
use Inc\DTO\Course\BatchCheckResultDTO;
use Inc\DTO\Task\CheckResultDTO;
use Inc\Enums\Assessment\AssessmentKind;
use Inc\Enums\Assessment\ScoringPolicy;
use Inc\Enums\Subject\TaskTemplate;
use Inc\Managers\Assessment\AssessmentManager;
use Inc\Managers\Wp\PostManager;
use Inc\Services\Course\BatchCheckService;
use Inc\Services\Task\TaskCheckerRegistry;
use Inc\Services\Template\TemplateResolver;
use PHPUnit\Framework\TestCase;

/**
 * Покрытие dry-run проверки в предпросмотре курса (#5). Гарантирует: вердикт
 * возвращается теми же проверяющими, что и штатная сдача; ничего не пишется
 * (у коллбека нет ни одной пишущей зависимости); доступ закрыт без права
 * AuthorLmsCourses. Регрессия на key-based Sanitizer (вход реально читается).
 */
class PreviewSolveCallbacksTest extends TestCase {

	private $posts;
	private $checkers;
	private $resolver;
	private $batch;
	private $assessments;
	private PreviewSolveCallbacks $cb;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_ajax();
		fs_test_reset_posts();
		$GLOBALS['_fs_test_can'] = true; // по умолчанию право есть; тест отзыва ставит false

		$this->posts       = $this->createMock( PostManager::class );
		$this->checkers    = $this->createMock( TaskCheckerRegistry::class );
		$this->resolver    = $this->createMock( TemplateResolver::class );
		$this->batch       = $this->createMock( BatchCheckService::class );
		$this->assessments = $this->createMock( AssessmentManager::class );

		$this->cb = new PreviewSolveCallbacks(
			$this->posts,
			$this->checkers,
			$this->resolver,
			$this->batch,
			$this->assessments
		);
	}

	private function stubTask( CheckResultDTO $verdict ): void {
		$this->posts->method( 'get' )->willReturn( new \WP_Post( array( 'ID' => 77 ) ) );
		$this->posts->method( 'getMeta' )->willReturn( array( 'choices' => array() ) );
		$this->resolver->method( 'resolveEnum' )->willReturn( TaskTemplate::Choice );
		$checker = $this->createMock( TaskCheckerInterface::class );
		$checker->method( 'check' )->willReturn( $verdict );
		$this->checkers->method( 'get' )->with( TaskTemplate::Choice )->willReturn( $checker );
	}

	// ── Задание ──────────────────────────────────────────────────────────────

	public function test_check_task_returns_correct_verdict(): void {
		$this->stubTask( CheckResultDTO::correct( 1.0 ) );
		$_POST = array( 'ref' => '77', 'answer' => json_encode( 'a' ) );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxPreviewCheckTask() );

		self::assertTrue( $r->success );
		self::assertTrue( $r->payload['is_correct'] );
		self::assertSame( 1.0, $r->payload['score'] );
		self::assertSame( 1.0, $r->payload['max_score'] );
		// Dry-run: без попыток/прогресса, шаг остаётся доступным для повторов.
		self::assertSame( 0, $r->payload['attempts_used'] );
		self::assertSame( 'available', $r->payload['step_status'] );
	}

	public function test_check_task_returns_incorrect_verdict(): void {
		$this->stubTask( CheckResultDTO::incorrect( 1.0 ) );
		$_POST = array( 'ref' => '77', 'answer' => json_encode( 'b' ) );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxPreviewCheckTask() );

		self::assertTrue( $r->success );
		self::assertFalse( $r->payload['is_correct'] );
		self::assertSame( 0.0, $r->payload['score'] );
	}

	public function test_check_task_manual_task_errors(): void {
		$this->posts->method( 'get' )->willReturn( new \WP_Post( array( 'ID' => 77 ) ) );
		$this->resolver->method( 'resolveEnum' )->willReturn( TaskTemplate::Choice );
		$this->checkers->method( 'get' )->willReturn( null ); // ручная проверка — авто-check недоступен
		$_POST = array( 'ref' => '77', 'answer' => json_encode( 'x' ) );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxPreviewCheckTask() );

		self::assertFalse( $r->success );
	}

	public function test_check_task_missing_task_errors(): void {
		$this->posts->method( 'get' )->willReturn( null );
		$_POST = array( 'ref' => '404', 'answer' => json_encode( 'a' ) );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxPreviewCheckTask() );

		self::assertFalse( $r->success );
	}

	public function test_check_task_denied_without_capability(): void {
		$GLOBALS['_fs_test_can'] = false; // нет AuthorLmsCourses
		$_POST = array( 'ref' => '77', 'answer' => json_encode( 'a' ) );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxPreviewCheckTask() );

		self::assertFalse( $r->success );
	}

	// ── Работа ───────────────────────────────────────────────────────────────

	public function test_check_work_grades_via_batch_without_persist(): void {
		$result = new BatchCheckResultDTO(
			array( 1 => array( 'verdict' => 'correct', 'score' => 1.0, 'maxScore' => 1.0 ) ),
			1,
			1,
			1.0,
			1.0,
			false
		);
		$this->batch->expects( self::once() )->method( 'check' )->willReturn( $result );

		$_POST = array( 'ref' => '55', 'answers' => json_encode( array( '1' => 'a' ) ) );
		$r     = fs_test_capture_json( fn() => $this->cb->ajaxPreviewCheckWork() );

		self::assertTrue( $r->success );
		self::assertSame( 1, $r->payload['correct'] );
		self::assertSame( 1, $r->payload['total'] );
		self::assertArrayHasKey( 1, $r->payload['per_task'] );
	}

	public function test_check_work_rejects_bad_answers(): void {
		$this->batch->expects( self::never() )->method( 'check' );
		$_POST = array( 'ref' => '55', 'answers' => 'not-json' );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxPreviewCheckWork() );

		self::assertFalse( $r->success );
	}

	// ── Контрольная ──────────────────────────────────────────────────────────

	public function test_check_assessment_grades_with_own_points_and_kind(): void {
		$asm = new AssessmentDTO(
			88,
			'inf',
			'КР',
			array( 1, 2 ),
			0,
			0,
			0.0,
			ScoringPolicy::Highest,
			'publish',
			AssessmentKind::Control,
			array( 1 => 2.0, 2 => 3.0 ),
			array()
		);
		$this->assessments->method( 'get' )->with( 88 )->willReturn( $asm );

		$result = new BatchCheckResultDTO( array( 1 => array( 'verdict' => 'correct' ) ), 1, 2, 2.0, 5.0, false );
		// Веса и вид контрольной берём из самой контрольной, а не от клиента.
		$this->batch->expects( self::once() )->method( 'check' )
			->with( self::anything(), array( 1 => 2.0, 2 => 3.0 ), AssessmentKind::Control )
			->willReturn( $result );

		$_POST = array( 'ref' => '88', 'answers' => json_encode( array( '1' => 'a', '2' => 'b' ) ) );
		$r     = fs_test_capture_json( fn() => $this->cb->ajaxPreviewCheckAssessment() );

		self::assertTrue( $r->success );
		self::assertSame( 2, $r->payload['total'] );
	}

	public function test_check_assessment_missing_errors(): void {
		$this->assessments->method( 'get' )->willReturn( null );
		$_POST = array( 'ref' => '999', 'answers' => json_encode( array() ) );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxPreviewCheckAssessment() );

		self::assertFalse( $r->success );
	}
}
