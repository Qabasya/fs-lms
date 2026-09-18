<?php

declare( strict_types=1 );

namespace Unit\Controllers\Task;

use Inc\Controllers\Task\TaskEditorButtonsController;
use PHPUnit\Framework\TestCase;

/**
 * Кнопки «Таблица» / «Код» / «Формула» вешаются только на экраны заданий:
 * у статей свой набор блоков, у остальных типов записей их быть не должно.
 */
class TaskEditorButtonsControllerTest extends TestCase {

	private TaskEditorButtonsController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->controller = new TaskEditorButtonsController();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_fs_test_screen'] );
		parent::tearDown();
	}

	private function onScreen( string $postType ): void {
		$GLOBALS['_fs_test_screen'] = (object) array( 'post_type' => $postType );
	}

	public function test_buttons_are_added_after_italic_on_task_screen(): void {
		$this->onScreen( 'inf_tasks' );

		$buttons = $this->controller->addButtons( array( 'bold', 'italic', 'bullist' ) );

		self::assertSame(
			array( 'bold', 'italic', 'fs_table', 'fs_code_block', 'fs_formula', 'bullist' ),
			$buttons
		);
	}

	public function test_buttons_are_added_on_problem_bank_screen(): void {
		$this->onScreen( 'fs_lms_problems' );

		self::assertContains( 'fs_table', $this->controller->addButtons( array( 'bold' ) ) );
	}

	public function test_other_screens_keep_their_toolbar(): void {
		$this->onScreen( 'page' );

		self::assertSame( array( 'bold', 'italic' ), $this->controller->addButtons( array( 'bold', 'italic' ) ) );
	}

	public function test_buttons_are_not_duplicated(): void {
		$this->onScreen( 'inf_tasks' );

		$once  = $this->controller->addButtons( array( 'bold' ) );
		$twice = $this->controller->addButtons( $once );

		self::assertSame( $once, $twice );
	}

	public function test_pre_class_is_whitelisted_on_task_screen(): void {
		// Без этого TinyMCE срезает class="fs-code-highlight", и подсветка на фронте блок не находит.
		$this->onScreen( 'inf_tasks' );

		$settings = $this->controller->allowCodeBlockClass( array() );

		self::assertStringContainsString( 'pre[class', $settings['extended_valid_elements'] );
	}

	public function test_existing_valid_elements_are_kept(): void {
		$this->onScreen( 'inf_tasks' );

		$settings = $this->controller->allowCodeBlockClass( array( 'extended_valid_elements' => 'span[data-x]' ) );

		self::assertStringStartsWith( 'span[data-x],', $settings['extended_valid_elements'] );
	}

	public function test_other_screens_keep_valid_elements_untouched(): void {
		$this->onScreen( 'page' );

		self::assertSame( array(), $this->controller->allowCodeBlockClass( array() ) );
	}
}
