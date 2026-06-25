<?php

declare( strict_types=1 );

namespace Unit\Callbacks\Task;

use Inc\Callbacks\Task\TaskContentCallbacks;
use Inc\Managers\Wp\MetaBoxManager;
use Inc\Services\Template\TemplateRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Регрессия на TaskContentCallbacks (модалка-редактор задачи, Этап 6, Phase F).
 *
 * Слой не имел тестов — из-за чего уехавший импорт `use Inc\Services\PostTypeResolver`
 * (вместо `...\Subject\PostTypeResolver`) ронял ajaxGetTaskEditorForm/ajaxSaveTaskContent
 * фаталом «class not found» → HTTP 500 → «Ошибка сети.» в модалке. Оба метода дёргают
 * `PostTypeResolver::tasks()`, поэтому тесты ниже ловят такую регрессию.
 */
class TaskContentCallbacksTest extends TestCase {

	private TaskContentCallbacks $callbacks;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_posts();
		fs_test_reset_ajax();
		$this->callbacks = new TaskContentCallbacks( new TemplateRegistry(), new MetaBoxManager() );
	}

	public function test_get_task_editor_form_returns_fields_html(): void {
		fs_test_seed_post( array( 'ID' => 5, 'post_type' => 'inf_tasks', 'post_title' => 'Задача' ) );
		$_POST = array( 'subject_key' => 'inf', 'template' => 'standard_task', 'post_id' => 5 );

		$r = fs_test_capture_json( fn() => $this->callbacks->ajaxGetTaskEditorForm() );

		self::assertTrue( $r->success );
		self::assertArrayHasKey( 'html', $r->payload );
		self::assertNotSame( '', $r->payload['html'] );
	}

	public function test_get_task_editor_form_unknown_template_errors(): void {
		$_POST = array( 'subject_key' => 'inf', 'template' => 'does_not_exist', 'post_id' => 5 );

		self::assertFalse( fs_test_capture_json( fn() => $this->callbacks->ajaxGetTaskEditorForm() )->success );
	}

	public function test_save_task_content_creates_subject_task(): void {
		$_POST = array( 'subject_key' => 'inf', 'template' => 'standard_task', 'title' => 'Новая задача', 'post_id' => 0 );

		$r = fs_test_capture_json( fn() => $this->callbacks->ajaxSaveTaskContent() );

		self::assertTrue( $r->success );
		self::assertGreaterThan( 0, $r->payload['id'] );
		self::assertSame( 'inf_tasks', get_post( $r->payload['id'] )->post_type );
		self::assertSame( 'Новая задача', $r->payload['title'] );
	}

	public function test_save_task_content_missing_subject_errors(): void {
		$_POST = array( 'template' => 'standard_task', 'title' => 'X' );

		self::assertFalse( fs_test_capture_json( fn() => $this->callbacks->ajaxSaveTaskContent() )->success );
	}
}
