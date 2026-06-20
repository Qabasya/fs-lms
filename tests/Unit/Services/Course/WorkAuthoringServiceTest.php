<?php

declare( strict_types=1 );

namespace Unit\Services\Course;

use Inc\Managers\Wp\PostManager;
use Inc\Managers\Wp\TermManager;
use Inc\Services\Course\WorkAuthoringService;
use PHPUnit\Framework\TestCase;

class WorkAuthoringServiceTest extends TestCase {

	private WorkAuthoringService $service;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_posts();
		$this->service = new WorkAuthoringService( new PostManager(), $this->createMock( TermManager::class ) );
	}

	public function test_task_candidates_are_scoped_to_subject(): void {
		fs_test_seed_post( array( 'ID' => 1, 'post_type' => 'inf_tasks', 'post_title' => 'A' ) );
		fs_test_seed_post( array( 'ID' => 2, 'post_type' => 'inf_tasks', 'post_title' => 'B' ) );
		fs_test_seed_post( array( 'ID' => 3, 'post_type' => 'rus_tasks', 'post_title' => 'Чужое' ) );

		$ids = array_column( $this->service->getTaskCandidates( 'inf', 0, 0, 'subject' ), 'id' );

		sort( $ids );
		self::assertSame( array( 1, 2 ), $ids );
	}

	public function test_validate_item_ids_drops_foreign_and_missing(): void {
		fs_test_seed_post( array( 'ID' => 1, 'post_type' => 'inf_tasks' ) );
		fs_test_seed_post( array( 'ID' => 3, 'post_type' => 'rus_tasks' ) );

		self::assertSame( array( 1 ), $this->service->validateItemIds( 'inf', array( 1, 3, 9999 ) ) );
	}

	public function test_validate_item_ids_keeps_problems(): void {
		fs_test_seed_post( array( 'ID' => 1, 'post_type' => 'inf_tasks' ) );
		fs_test_seed_post( array( 'ID' => 5, 'post_type' => 'fs_lms_problems' ) );

		$ids = $this->service->validateItemIds( 'inf', array( 1, 5 ) );
		self::assertSame( array( 1, 5 ), $ids );
	}
}
