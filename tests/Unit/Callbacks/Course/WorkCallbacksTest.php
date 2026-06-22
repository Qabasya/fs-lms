<?php

declare( strict_types=1 );

namespace Unit\Callbacks\Course;

use Inc\Callbacks\Course\WorkCallbacks;
use Inc\Managers\Course\WorkManager;
use Inc\Services\Course\WorkAuthoringService;
use PHPUnit\Framework\TestCase;

class WorkCallbacksTest extends TestCase {

	private WorkAuthoringService $auth;
	private WorkManager          $manager;
	private WorkCallbacks        $cb;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_ajax();
		$this->auth    = $this->createMock( WorkAuthoringService::class );
		$this->manager = $this->createMock( WorkManager::class );
		$this->cb      = new WorkCallbacks( $this->auth, $this->manager );
	}

	public function test_get_task_candidates_returns_service_result(): void {
		$this->auth->method( 'getTaskCandidates' )->willReturn( array( array( 'id' => 1, 'title' => 'Задача' ) ) );
		$_POST = array( 'subject_key' => 'inf', 'task_type' => '', 'collection' => '', 'scope' => 'mine', 'search' => '' );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxGetWorkTaskCandidates() );

		self::assertTrue( $r->success );
		self::assertSame( array( array( 'id' => 1, 'title' => 'Задача' ) ), $r->payload );
	}

	public function test_get_task_candidates_missing_subject_errors(): void {
		$this->auth->expects( $this->never() )->method( 'getTaskCandidates' );
		$_POST = array();

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxGetWorkTaskCandidates() )->success );
	}

	public function test_create_work_draft_returns_id_and_title(): void {
		$this->manager->expects( $this->once() )->method( 'create' )->willReturn( 5 );
		$_POST = array( 'subject_key' => 'inf', 'title' => 'Моя работа', 'work_type' => 'homework' );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxCreateWorkDraft() );

		self::assertTrue( $r->success );
		self::assertSame( 5, $r->payload['id'] );
		self::assertSame( 'Моя работа', $r->payload['title'] );
	}

	public function test_create_problem_draft_returns_id(): void {
		$this->auth->expects( $this->once() )->method( 'createProblemDraft' )->with( 'Моя задача' )->willReturn( 7 );
		$_POST = array( 'title' => 'Моя задача' );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxCreateProblemDraft() );

		self::assertTrue( $r->success );
		self::assertSame( 7, $r->payload['id'] );
	}

	public function test_save_work_items_persists_item_ids(): void {
		$this->manager->expects( $this->once() )->method( 'setItemIds' )
			->with( 5, array( 10, 20, 30 ) )->willReturn( true );
		$_POST = array( 'work_id' => '5', 'item_ids' => array( '10', '20', '30' ) );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxSaveWorkItems() );

		self::assertTrue( $r->success );
		self::assertSame( 3, $r->payload['count'] );
	}

	public function test_save_work_items_errors_when_work_missing(): void {
		$this->manager->method( 'setItemIds' )->willReturn( false );
		$_POST = array( 'work_id' => '999', 'item_ids' => array( '10' ) );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxSaveWorkItems() )->success );
	}
}
