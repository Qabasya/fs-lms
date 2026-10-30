<?php

declare( strict_types=1 );

namespace Unit\Services\Task;

use Inc\Services\Task\TaskRevisionService;
use PHPUnit\Framework\TestCase;

/**
 * История изменений заданий: поля задания лежат в мете, поэтому ревизии WordPress переносят её (`fs_lms_meta`, шаблон),
 * а правка одной меты считается изменением — иначе редакция не создавалась бы.
 */
class TaskRevisionServiceTest extends TestCase {

	private TaskRevisionService $service;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_posts();
		$GLOBALS['_fs_test_meta'] = array();
		$this->service            = new TaskRevisionService();
	}

	private function post( int $id, string $type = 'inf_tasks' ): \WP_Post {
		return new \WP_Post( array( 'ID' => $id, 'post_type' => $type ) );
	}

	public function test_task_meta_keys_are_revisioned_only_for_task_post_types(): void {
		self::assertSame(
			array( 'x', 'fs_lms_meta', 'fs_lms_template_type' ),
			$this->service->revisionMetaKeys( array( 'x' ), 'inf_tasks' )
		);
		self::assertSame( array( 'x' ), $this->service->revisionMetaKeys( array( 'x' ), 'inf_articles' ) );
		self::assertSame( array(), $this->service->revisionMetaKeys( array(), 'page' ) );
	}

	public function test_changed_task_meta_makes_a_new_revision(): void {
		$GLOBALS['_fs_test_meta'][10] = array( 'fs_lms_meta' => array( 'task_answer' => '5' ), 'fs_lms_template_type' => 'standard_task' );
		$GLOBALS['_fs_test_meta'][11] = array( 'fs_lms_meta' => array( 'task_answer' => '4' ), 'fs_lms_template_type' => 'standard_task' );

		self::assertTrue( $this->service->hasChanged( false, $this->post( 11, 'revision' ), $this->post( 10 ) ) );
	}

	public function test_same_meta_does_not_force_a_revision(): void {
		$same = array( 'fs_lms_meta' => array( 'task_answer' => '5' ), 'fs_lms_template_type' => 'standard_task' );
		$GLOBALS['_fs_test_meta'][10] = $same;
		$GLOBALS['_fs_test_meta'][11] = $same;

		self::assertFalse( $this->service->hasChanged( false, $this->post( 11, 'revision' ), $this->post( 10 ) ) );
	}

	/** Первая редакция (до включения истории) меты не хранит: правка считается изменением. */
	public function test_revision_without_stored_meta_counts_as_changed(): void {
		$GLOBALS['_fs_test_meta'][10] = array( 'fs_lms_meta' => array( 'task_answer' => '5' ) );

		self::assertTrue( $this->service->hasChanged( false, $this->post( 11, 'revision' ), $this->post( 10 ) ) );
	}

	public function test_already_changed_post_stays_changed_and_other_types_are_untouched(): void {
		self::assertTrue( $this->service->hasChanged( true, $this->post( 11, 'revision' ), $this->post( 10 ) ) );
		self::assertFalse( $this->service->hasChanged( false, $this->post( 11, 'revision' ), $this->post( 10, 'page' ) ) );
	}
}
