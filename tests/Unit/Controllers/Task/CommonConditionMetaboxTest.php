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
