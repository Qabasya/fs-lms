<?php

declare( strict_types=1 );

namespace Unit\Services\Course;

use Inc\Enums\Course\StepType;
use Inc\Managers\Course\LessonManager;
use Inc\Managers\Wp\PostManager;
use Inc\Services\Course\LessonAuthoringService;
use PHPUnit\Framework\TestCase;

class LessonAuthoringServiceTest extends TestCase {

	private LessonAuthoringService $service;
	private LessonManager          $lessons;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_posts();
		$posts         = new PostManager();
		$this->lessons = new LessonManager( $posts );
		$this->service = new LessonAuthoringService( $posts, $this->lessons, new \Inc\Services\Template\TemplateRegistry() );
	}

	/**
	 * @param int $id
	 * @return string[] ключи шагов урока по порядку
	 */
	private function stepKeys( int $id ): array {
		return array_map( static fn ( $s ): string => $s->key, $this->lessons->get( $id )->steps );
	}

	private function seedLesson( int $id, string $subject, array $stepKeys ): void {
		$steps = array_map(
			static fn ( string $k ): array => array( 'key' => $k, 'type' => 'text', 'payload' => array( 'content' => $k ) ),
			$stepKeys
		);
		fs_test_seed_post(
			array( 'ID' => $id, 'post_type' => $subject . '_lessons', 'post_title' => 'Урок ' . $id ),
			array( 'fs_lms_meta' => array( 'steps' => $steps ) )
		);
	}

	public function test_work_candidates_expose_work_type_from_meta(): void {
		fs_test_seed_post(
			array( 'ID' => 1, 'post_type' => 'inf_works', 'post_title' => 'ДЗ' ),
			array( 'fs_lms_meta' => array( 'work_type' => 'homework' ) )
		);

		$candidates = $this->service->getWorkCandidates( 'inf', '', 'subject' );

		self::assertCount( 1, $candidates );
		self::assertSame( 'homework', $candidates[0]['work_type'] );
	}

	public function test_work_candidates_filter_by_type(): void {
		fs_test_seed_post( array( 'ID' => 1, 'post_type' => 'inf_works' ), array( 'fs_lms_meta' => array( 'work_type' => 'homework' ) ) );
		fs_test_seed_post( array( 'ID' => 2, 'post_type' => 'inf_works' ), array( 'fs_lms_meta' => array( 'work_type' => 'practice' ) ) );

		$ids = array_column( $this->service->getWorkCandidates( 'inf', 'practice', 'subject' ), 'id' );

		self::assertSame( array( 2 ), $ids );
	}

	public function test_validate_work_ids_drops_foreign(): void {
		fs_test_seed_post( array( 'ID' => 1, 'post_type' => 'inf_works' ) );
		fs_test_seed_post( array( 'ID' => 2, 'post_type' => 'inf_lessons' ) );

		self::assertSame( array( 1 ), $this->service->validateWorkIds( 'inf', array( 1, 2 ) ) );
	}

	public function test_build_steps_assigns_key_when_missing_and_keeps_existing(): void {
		$steps = $this->service->buildSteps( array(
			array( 'type' => 'text', 'payload' => array( 'content' => 'A' ) ),
			array( 'key' => 'fixed', 'type' => 'work', 'payload' => array( 'ref' => 5 ) ),
		) );

		self::assertCount( 2, $steps );
		self::assertNotSame( '', $steps[0]->key );
		self::assertSame( StepType::Text, $steps[0]->type );
		self::assertSame( 'fixed', $steps[1]->key );
		self::assertSame( 5, $steps[1]->payload['ref'] );
	}

	public function test_build_steps_drops_unknown_type_and_non_array(): void {
		$steps = $this->service->buildSteps( array(
			array( 'type' => 'bogus', 'payload' => array() ),
			'garbage',
			array( 'type' => 'video', 'payload' => array( 'url' => 'x' ) ),
		) );

		self::assertCount( 1, $steps );
		self::assertSame( StepType::Video, $steps[0]->type );
	}

	public function test_step_candidates_work(): void {
		fs_test_seed_post( array( 'ID' => 1, 'post_type' => 'inf_works', 'post_title' => 'ДЗ' ) );

		$candidates = $this->service->getStepCandidates( 'inf', 'work' );

		self::assertSame( array( array( 'id' => 1, 'title' => 'ДЗ' ) ), $candidates );
	}

	public function test_step_candidates_task_source_subject_vs_bank(): void {
		fs_test_seed_post( array( 'ID' => 1, 'post_type' => 'inf_tasks', 'post_title' => 'Задача предмета' ) );
		fs_test_seed_post( array( 'ID' => 2, 'post_type' => 'fs_lms_problems', 'post_title' => 'Задача банка' ) );

		self::assertSame( array( 1 ), array_column( $this->service->getStepCandidates( 'inf', 'task', 'subject' ), 'id' ) );
		self::assertSame( array( 2 ), array_column( $this->service->getStepCandidates( 'inf', 'task', 'bank' ), 'id' ) );
	}

	public function test_step_candidates_task_all_merges_subject_and_bank(): void {
		fs_test_seed_post( array( 'ID' => 1, 'post_type' => 'inf_tasks', 'post_title' => 'Задача предмета' ) );
		fs_test_seed_post( array( 'ID' => 2, 'post_type' => 'fs_lms_problems', 'post_title' => 'Задача банка' ) );

		$all = $this->service->getStepCandidates( 'inf', 'task', 'all' );

		self::assertSame( array( 1, 2 ), array_column( $all, 'id' ) );
		self::assertSame( array( 'subject', 'bank' ), array_column( $all, 'source' ) );
	}

	public function test_step_candidates_unknown_kind_is_empty(): void {
		self::assertSame( array(), $this->service->getStepCandidates( 'inf', 'whatever' ) );
	}

	public function test_step_candidates_lesson_kind(): void {
		fs_test_seed_post( array( 'ID' => 1, 'post_type' => 'inf_lessons', 'post_title' => 'Урок 1' ) );

		self::assertSame( array( array( 'id' => 1, 'title' => 'Урок 1' ) ), $this->service->getStepCandidates( 'inf', 'lesson' ) );
	}

	public function test_create_task_draft_makes_subject_task_draft(): void {
		$id   = $this->service->createTaskDraft( 'inf', 'Задача 1' );
		$post = get_post( $id );

		self::assertGreaterThan( 0, $id );
		self::assertSame( 'inf_tasks', $post->post_type );
		self::assertSame( 'draft', $post->post_status );
		self::assertSame( 'Задача 1', $post->post_title );
	}

	public function test_create_assessment_draft_uses_assessments_cpt(): void {
		$id = $this->service->createAssessmentDraft( 'inf', 'КР' );
		self::assertSame( 'inf_assessments', get_post( $id )->post_type );
	}

	public function test_create_article_draft_uses_articles_cpt(): void {
		$id = $this->service->createArticleDraft( 'inf', 'Статья' );
		self::assertSame( 'inf_articles', get_post( $id )->post_type );
	}
}
