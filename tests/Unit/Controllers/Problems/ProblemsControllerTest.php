<?php

declare( strict_types=1 );

namespace Unit\Controllers\Problems;

use Inc\Controllers\Builders\ProblemListFilters;
use Inc\Controllers\Problems\ProblemsController;
use Inc\Managers\Wp\PostManager;
use Inc\Registrars\ProblemBankRegistrar;
use Inc\Services\Task\TaskPublishGuard;
use Inc\Services\Task\TaskPublishValidator;
use Inc\Services\Template\TemplateRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Регрессия: валидация публикации задачи банка читала только $_POST — быстрое и
 * массовое редактирование, а также программные вставки (импорт пакета) не шлют
 * форму метабокса, валидатор сваливался на «Стандартный» шаблон и откатывал
 * опубликованную задачу в черновик («Заполните «Условие задания»»).
 */
class ProblemsControllerTest extends TestCase {

	private TaskPublishValidator $validator;
	private PostManager          $posts;
	private ProblemsController   $controller;

	protected function setUp(): void {
		parent::setUp();
		$_POST = array();

		$this->validator  = $this->createMock( TaskPublishValidator::class );
		$this->posts      = $this->createMock( PostManager::class );

		$this->controller = new ProblemsController(
			$this->createMock( TemplateRegistry::class ),
			$this->posts,
			$this->validator,
			new TaskPublishGuard(),
			$this->createMock( ProblemBankRegistrar::class ),
			$this->createMock( ProblemListFilters::class )
		);
	}

	/** @return array<string, mixed> */
	private function postData( string $status = 'publish' ): array {
		return array(
			'post_status' => $status,
			'post_type'   => 'fs_lms_problems',
			'post_title'  => 'Задача',
		);
	}

	public function test_quick_edit_uses_stored_meta_and_template(): void {
		$stored = array( 'task_19_condition' => '<p>Условие</p>', 'task_code' => 'print(1)' );

		$this->posts->method( 'getMeta' )->willReturnMap( array(
			array( 15, 'fs_lms_meta', true, $stored ),
			array( 15, 'fs_lms_template_type', true, 'triple_task' ),
		) );

		$this->validator->expects( $this->once() )
			->method( 'getSoftError' )
			->with( $stored, 'triple_task' )
			->willReturn( null );

		$data = $this->controller->validateBeforePublish( $this->postData(), array( 'ID' => 15 ) );

		self::assertSame( 'publish', $data['post_status'] );
	}

	public function test_programmatic_insert_without_form_is_not_validated(): void {
		$this->validator->expects( $this->never() )->method( 'getSoftError' );

		$data = $this->controller->validateBeforePublish( $this->postData(), array( 'ID' => 0 ) );

		self::assertSame( 'publish', $data['post_status'] );
	}

	public function test_form_data_wins_over_stored_state(): void {
		$this->posts->expects( $this->never() )->method( 'getMeta' );

		$_POST = array(
			'fs_lms_meta'          => array( 'task_condition' => '' ),
			'fs_lms_template_type' => 'standard_task',
		);

		$this->validator->expects( $this->once() )
			->method( 'getSoftError' )
			->with( array( 'task_condition' => '' ), 'standard_task' )
			->willReturn( 'Заполните «Условие задания».' );

		$data = $this->controller->validateBeforePublish( $this->postData(), array( 'ID' => 15 ) );

		self::assertSame( 'draft', $data['post_status'] );
	}

	public function test_other_post_types_are_untouched(): void {
		$this->validator->expects( $this->never() )->method( 'getSoftError' );

		$data = $this->controller->validateBeforePublish(
			array( 'post_status' => 'publish', 'post_type' => 'page', 'post_title' => 'Стр.' ),
			array( 'ID' => 15 )
		);

		self::assertSame( 'publish', $data['post_status'] );
	}
}
