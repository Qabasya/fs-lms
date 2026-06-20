<?php

declare( strict_types=1 );

namespace Unit\Callbacks\Course;

use Inc\Callbacks\Course\LessonCallbacks;
use Inc\Managers\Course\LessonManager;
use Inc\Managers\Wp\PostManager;
use Inc\Services\Course\LessonAuthoringService;
use PHPUnit\Framework\TestCase;

/**
 * Покрытие AJAX-коллбека переноса шага между уроками (T1.5.5).
 */
class LessonCallbacksTest extends TestCase {

	private LessonCallbacks $callbacks;
	private LessonManager   $lessons;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_posts();
		fs_test_reset_ajax();
		$posts           = new PostManager();
		$this->lessons   = new LessonManager( $posts );
		$this->callbacks = new LessonCallbacks( new LessonAuthoringService( $posts, $this->lessons ), $this->lessons );
	}

	private function seedLesson( int $id, string $subject, array $stepKeys ): void {
		$steps = array_map(
			static fn ( string $k ): array => array( 'key' => $k, 'type' => 'text', 'payload' => array( 'title' => $k ) ),
			$stepKeys
		);
		fs_test_seed_post(
			array( 'ID' => $id, 'post_type' => $subject . '_lessons', 'post_title' => 'Урок ' . $id ),
			array( 'fs_lms_meta' => array( 'steps' => $steps ) )
		);
	}

	private function stepKeys( int $id ): array {
		return array_map( static fn ( $s ): string => $s->key, $this->lessons->get( $id )->steps );
	}

	public function test_move_step_success_moves_between_lessons(): void {
		$this->seedLesson( 10, 'inf', array( 's_a', 's_b' ) );
		$this->seedLesson( 20, 'inf', array( 's_c' ) );
		$_POST = array( 'source_lesson_id' => '10', 'target_lesson_id' => '20', 'step_key' => 's_a' );

		$r = fs_test_capture_json( fn() => $this->callbacks->ajaxMoveLessonStep() );

		self::assertTrue( $r->success );
		self::assertTrue( $r->payload['moved'] );
		self::assertSame( array( 's_b' ), $this->stepKeys( 10 ) );
		self::assertSame( array( 's_c', 's_a' ), $this->stepKeys( 20 ) );
	}

	public function test_move_step_missing_step_errors(): void {
		$this->seedLesson( 10, 'inf', array( 's_a' ) );
		$this->seedLesson( 20, 'inf', array() );
		$_POST = array( 'source_lesson_id' => '10', 'target_lesson_id' => '20', 'step_key' => 's_missing' );

		$r = fs_test_capture_json( fn() => $this->callbacks->ajaxMoveLessonStep() );

		self::assertFalse( $r->success );
		self::assertSame( array( 's_a' ), $this->stepKeys( 10 ) );
	}

	public function test_move_step_missing_param_errors(): void {
		// Регрессия-гард: без source_lesson_id requireInt должен отдать error.
		$_POST = array( 'target_lesson_id' => '20', 'step_key' => 's_a' );

		$r = fs_test_capture_json( fn() => $this->callbacks->ajaxMoveLessonStep() );

		self::assertFalse( $r->success );
	}

	public function test_create_task_draft_returns_id_and_creates_subject_task(): void {
		$_POST = array( 'subject_key' => 'inf', 'title' => 'Задача' );

		$r = fs_test_capture_json( fn() => $this->callbacks->ajaxCreateTaskDraft() );

		self::assertTrue( $r->success );
		self::assertGreaterThan( 0, $r->payload['id'] );
		self::assertSame( 'inf_tasks', get_post( $r->payload['id'] )->post_type );
	}

	public function test_create_assessment_draft_returns_id(): void {
		$_POST = array( 'subject_key' => 'inf', 'title' => 'КР' );

		$r = fs_test_capture_json( fn() => $this->callbacks->ajaxCreateAssessmentDraft() );

		self::assertTrue( $r->success );
		self::assertSame( 'inf_assessments', get_post( $r->payload['id'] )->post_type );
	}

	public function test_create_article_draft_returns_id(): void {
		$_POST = array( 'subject_key' => 'inf', 'title' => 'Материал' );

		$r = fs_test_capture_json( fn() => $this->callbacks->ajaxCreateArticleDraft() );

		self::assertTrue( $r->success );
		self::assertSame( 'inf_articles', get_post( $r->payload['id'] )->post_type );
	}

	public function test_create_task_draft_missing_subject_errors(): void {
		$_POST = array();

		self::assertFalse( fs_test_capture_json( fn() => $this->callbacks->ajaxCreateTaskDraft() )->success );
	}
}
