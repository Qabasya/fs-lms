<?php

declare( strict_types=1 );

namespace Unit\Controllers\Task;

use Inc\Controllers\Task\MetaBoxController;
use Inc\DTO\Subject\SubjectDTO;
use Inc\Managers\Wp\MetaBoxManager;
use Inc\Managers\Wp\PostManager;
use Inc\MetaBoxes\Templates\FileAnswerTaskTemplate;
use Inc\MetaBoxes\Templates\StandardTaskTemplate;
use Inc\MetaBoxes\Templates\ThreeInOneTemplate;
use Inc\Registrars\MetaBoxRegistrar;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Services\Task\TaskBundleService;
use Inc\Services\Template\TemplateRegistry;
use Inc\Services\Template\TemplateResolver;
use PHPUnit\Framework\TestCase;

/**
 * Метабокс «Общее условие»: хук `add_meta_boxes` срабатывает для любого типа
 * записи, а резолвер у записи без номера задания отдаёт «Стандартное» —
 * бокс вылезал у статей и задач банка.
 */
class CommonConditionMetaboxTest extends TestCase {
	public function test_programmatically_inserted_public_task_is_normalized_after_insert(): void {
		$_POST = array();
		$normalizer = $this->createMock( \Inc\Services\Task\TaskFileNormalizationService::class );
		$normalizer->expects( self::once() )->method( 'apply' )->with( 7 )->willReturn( array( 'updated' => 1, 'warnings' => array() ) );
		$controller = new MetaBoxController( $this->createStub( SubjectRepository::class ), $this->createStub( MetaBoxRegistrar::class ), $this->createStub( TemplateRegistry::class ), $this->createStub( TemplateResolver::class ), $this->createStub( MetaBoxManager::class ), $this->createStub( PostManager::class ), $this->createStub( TaskBundleService::class ), $normalizer );
		$controller->handleProgrammaticTaskInsert( 7, new \WP_Post( array( 'ID' => 7, 'post_type' => 'inf_tasks', 'post_status' => 'publish' ) ), false );
	}
	public function test_scheduled_publication_normalizes_only_public_task(): void {
		$normalizer = $this->createMock( \Inc\Services\Task\TaskFileNormalizationService::class );
		$normalizer->expects( self::once() )->method( 'apply' )->with( 7 )->willReturn( array( 'updated' => 1, 'warnings' => array() ) );
		$controller = new MetaBoxController( $this->createStub( SubjectRepository::class ), $this->createStub( MetaBoxRegistrar::class ), $this->createStub( TemplateRegistry::class ), $this->createStub( TemplateResolver::class ), $this->createStub( MetaBoxManager::class ), $this->createStub( PostManager::class ), $this->createStub( TaskBundleService::class ), $normalizer );
		$controller->handleScheduledFilePublication( 'publish', 'future', new \WP_Post( array( 'ID' => 7, 'post_type' => 'inf_tasks' ) ) );
		$controller->handleScheduledFilePublication( 'publish', 'future', new \WP_Post( array( 'ID' => 8, 'post_type' => 'fs_lms_problems' ) ) );
		$controller->handleScheduledFilePublication( 'publish', 'draft', new \WP_Post( array( 'ID' => 9, 'post_type' => 'inf_tasks' ) ) );
	}
	public function test_saved_published_task_normalizes_files_after_meta_save(): void {
		fs_test_reset_posts();
		fs_test_seed_post( array( 'ID' => 7, 'post_type' => 'inf_tasks', 'post_status' => 'publish' ) );
		$_POST = array( 'fs_lms_meta_nonce' => 'valid', 'fs_lms_meta' => array() );
		$template = new StandardTaskTemplate();
		$resolver = $this->createStub( TemplateResolver::class );
		$resolver->method( 'resolveId' )->willReturn( $template->get_id() );
		$registry = $this->createStub( TemplateRegistry::class );
		$registry->method( 'get' )->willReturn( $template );
		$saved = false;
		$boxes = $this->createMock( MetaBoxManager::class );
		$boxes->method( 'saveFieldsMerge' )->willReturnCallback( static function () use ( &$saved ): void { $saved = true; } );
		$normalizer = $this->createMock( \Inc\Services\Task\TaskFileNormalizationService::class );
		$normalizer->expects( self::once() )->method( 'apply' )->with( 7 )->willReturnCallback( static function () use ( &$saved ): array { self::assertTrue( $saved ); return array( 'updated' => 1, 'warnings' => array() ); } );
		$controller = new MetaBoxController( $this->createStub( SubjectRepository::class ), $this->createStub( MetaBoxRegistrar::class ), $registry, $resolver, $boxes, $this->createStub( PostManager::class ), $this->createStub( TaskBundleService::class ), $normalizer );
		$controller->handleMetaSave( 7 );
	}

	/** @var string[] ID зарегистрированных метабоксов */
	private array $boxes = array();

	private function controller( object $template ): MetaBoxController {
		$subjects = $this->createMock( SubjectRepository::class );
		$subjects->method( 'readAll' )->willReturn( array( new SubjectDTO( 'inf', 'Информатика' ) ) );

		$registrar = $this->createMock( MetaBoxRegistrar::class );
		$registrar->method( 'add' )->willReturnCallback( function ( string $id ) use ( &$registrar ) {
			$this->boxes[] = $id;
			return $registrar;
		} );

		$registry = $this->createMock( TemplateRegistry::class );
		$registry->method( 'get' )->willReturn( $template );

		$resolver = $this->createMock( TemplateResolver::class );
		$resolver->method( 'resolveId' )->willReturn( $template->get_id() );

		return new MetaBoxController(
			$subjects,
			$registrar,
			$registry,
			$resolver,
			$this->createMock( MetaBoxManager::class ),
			$this->createMock( PostManager::class ),
			$this->createMock( TaskBundleService::class ),
		);
	}

	private function boxesFor( string $postType, object $template ): array {
		$this->boxes = array();
		$post        = new \WP_Post( array( 'ID' => 7, 'post_type' => $postType ) );

		$this->controller( $template )->handleAddMetaBoxes( $postType, $post );

		return $this->boxes;
	}

	public function test_subject_task_with_standard_template_gets_the_box(): void {
		self::assertContains( 'fs_lms_task_common_condition', $this->boxesFor( 'inf_tasks', new StandardTaskTemplate() ) );
	}

	public function test_file_answer_template_gets_the_box(): void {
		self::assertContains( 'fs_lms_task_common_condition', $this->boxesFor( 'inf_tasks', new FileAnswerTaskTemplate() ) );
	}

	public function test_template_without_common_field_has_no_box(): void {
		self::assertNotContains( 'fs_lms_task_common_condition', $this->boxesFor( 'inf_tasks', new ThreeInOneTemplate() ) );
	}

	public function test_article_has_no_box(): void {
		self::assertNotContains( 'fs_lms_task_common_condition', $this->boxesFor( 'inf_articles', new StandardTaskTemplate() ) );
	}

	public function test_bank_problem_has_no_box(): void {
		self::assertNotContains( 'fs_lms_task_common_condition', $this->boxesFor( 'fs_lms_problems', new StandardTaskTemplate() ) );
	}

	public function test_page_has_no_box(): void {
		self::assertNotContains( 'fs_lms_task_common_condition', $this->boxesFor( 'page', new StandardTaskTemplate() ) );
	}
}
