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
		$this->callbacks = new LessonCallbacks( new LessonAuthoringService( $posts, $this->lessons, new \Inc\Services\Template\TemplateRegistry() ), $this->lessons );
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

	public function test_save_lesson_steps_preserves_needs_review_flag(): void {
		$this->seedLesson( 5, 'inf', array( 's1' ) );
		$_POST = array(
			'lesson_id'   => '5',
			'subject_key' => 'inf',
			'steps'       => array(
				array( 'key' => 's1', 'type' => 'text', 'payload' => array( 'title' => 'A', 'content' => 'B', 'needs_review' => '1' ) ),
				array( 'key' => 's2', 'type' => 'text', 'payload' => array( 'title' => 'C', 'content' => 'D' ) ),
			),
		);

		$r = fs_test_capture_json( fn() => $this->callbacks->ajaxSaveLessonSteps() );

		self::assertTrue( $r->success );
		$steps = $this->lessons->get( 5 )->steps;
		self::assertTrue( $steps[0]->payload['needs_review'] );            // флаг переживает sanitizeStep
		self::assertArrayNotHasKey( 'needs_review', $steps[1]->payload );  // без флага ключ не добавляется
	}

	public function test_save_lesson_steps_persists_video_chapters_and_attachments(): void {
		// D21 (T14.12): главы и вложения видео-шага переживают sanitizeStep —
		// сортировка по времени, отброс пустых строк, только int-id вложений.
		$this->seedLesson( 5, 'inf', array( 's1' ) );
		$_POST = array(
			'lesson_id'   => '5',
			'subject_key' => 'inf',
			'steps'       => array(
				array(
					'key'     => 's1',
					'type'    => 'video',
					'payload' => array(
						'title'       => 'Видео',
						'url'         => 'https://s3.example.com/v.mp4',
						'chapters'    => array(
							array( 't' => '220', 'title' => 'Шаг и отсчёт' ),
							array( 't' => '0', 'title' => 'Зачем range()' ),
							array( 't' => '0', 'title' => '' ), // пустая строка — отброс
						),
						'attachments' => array( '7', 'мусор', '0', 9 ),
					),
				),
			),
		);

		$r = fs_test_capture_json( fn() => $this->callbacks->ajaxSaveLessonSteps() );

		self::assertTrue( $r->success );
		$payload = $this->lessons->get( 5 )->steps[0]->payload;
		self::assertSame(
			array(
				array( 't' => 0, 'title' => 'Зачем range()' ),
				array( 't' => 220, 'title' => 'Шаг и отсчёт' ),
			),
			$payload['chapters']
		);
		self::assertSame( array( 7, 9 ), $payload['attachments'] );
	}
}
