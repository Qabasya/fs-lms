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

	public function test_item_candidates_pass_source_and_page(): void {
		$this->auth->expects( $this->once() )->method( 'getItemCandidates' )
			->with( 'inf', 0, 'mine', 'дроби', 'public', 3 )
			->willReturn( array( array( 'id' => 9, 'title' => 'Дроби' ) ) );
		$_POST = array( 'subject_key' => 'inf', 'search' => 'дроби', 'source' => 'public', 'page' => '3' );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxGetWorkItemCandidates() );

		self::assertTrue( $r->success );
		self::assertSame( 9, $r->payload[0]['id'] );
	}

	public function test_item_candidates_default_to_first_page(): void {
		$this->auth->expects( $this->once() )->method( 'getItemCandidates' )
			->with( 'inf', 0, 'mine', '', 'subject', 1 )
			->willReturn( array() );
		$_POST = array( 'subject_key' => 'inf' );

		self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxGetWorkItemCandidates() )->success );
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

	public function test_item_candidates_pass_work_type(): void {
		$this->auth->expects( $this->once() )->method( 'getItemCandidates' )
			->with( 'inf', 0, 'mine', '', 'subject', 1, 'survey' )
			->willReturn( array() );
		$_POST = array( 'subject_key' => 'inf', 'work_type' => 'survey' );

		self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxGetWorkItemCandidates() )->success );
	}

	public function test_save_work_items_rejects_templates_not_allowed_for_survey(): void {
		$this->manager->method( 'get' )->willReturn( new \Inc\DTO\Course\WorkDTO(
			id          : 5,
			subjectKey  : 'inf',
			title       : 'Опрос',
			workType    : \Inc\Enums\Course\WorkType::Survey,
			itemIds     : array(),
			instructions: '',
			authorId    : 1,
			status      : 'draft',
		) );
		$this->auth->method( 'disallowedItemIds' )->willReturn( array( 20 ) );
		$this->manager->expects( $this->never() )->method( 'setItemIds' );
		$_POST = array( 'work_id' => '5', 'item_ids' => array( '10', '20' ) );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxSaveWorkItems() );

		self::assertFalse( $r->success );
		self::assertStringContainsString( 'Опрос', (string) ( $r->payload['message'] ?? $r->payload ) );
	}

	/** Практика не принимает опросные шаблоны: сохранение состава с таким заданием — ошибка с перечнем допустимых. */
	public function test_save_work_items_rejects_survey_only_templates_in_practice(): void {
		$this->manager->method( 'get' )->willReturn( new \Inc\DTO\Course\WorkDTO(
			id: 5, subjectKey: 'inf', title: 'Практика', workType: \Inc\Enums\Course\WorkType::Practice,
			itemIds: array( 10 ), instructions: '', authorId: 1, status: 'draft',
		) );
		$this->auth->method( 'disallowedItemIds' )->with( \Inc\Enums\Course\WorkType::Practice, array( 20 ) )->willReturn( array( 20 ) );
		$this->manager->expects( $this->never() )->method( 'setItemIds' );
		$_POST = array( 'work_id' => '5', 'item_ids' => array( '10', '20' ) );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxSaveWorkItems() );

		self::assertFalse( $r->success );
		self::assertStringContainsString( 'Практика', (string) ( $r->payload['message'] ?? $r->payload ) );
	}

	/** Уже стоящие в работе элементы не пересматриваются: старую практику с опросным заданием можно править дальше. */
	public function test_save_work_items_keeps_legacy_survey_only_items_already_in_the_work(): void {
		$this->manager->method( 'get' )->willReturn( new \Inc\DTO\Course\WorkDTO(
			id: 5, subjectKey: 'inf', title: 'Практика', workType: \Inc\Enums\Course\WorkType::Practice,
			itemIds: array( 10, 20 ), instructions: '', authorId: 1, status: 'draft',
		) );
		// 20 — опросное, но оно уже в работе; проверяется только добавляемое (30).
		$this->auth->expects( $this->once() )->method( 'disallowedItemIds' )->with( \Inc\Enums\Course\WorkType::Practice, array( 30 ) )->willReturn( array() );
		$this->auth->method( 'validateItemIds' )->willReturn( array( 30 ) );
		$this->manager->expects( $this->once() )->method( 'setItemIds' )->with( 5, array( 20, 10, 30 ) )->willReturn( true );
		$_POST = array( 'work_id' => '5', 'item_ids' => array( '20', '10', '30' ) );

		self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxSaveWorkItems() )->success );
	}

	/** Новые элементы работы проверяются: чужой черновик или не задание в работу не попадают. */
	public function test_save_work_items_rejects_new_items_that_do_not_validate(): void {
		$this->manager->method( 'get' )->willReturn( new \Inc\DTO\Course\WorkDTO(
			id          : 5,
			subjectKey  : 'inf',
			title       : 'Работа',
			workType    : \Inc\Enums\Course\WorkType::Practice,
			itemIds     : array( 10 ),
			instructions: '',
			authorId    : 1,
			status      : 'draft',
		) );
		$this->auth->method( 'disallowedItemIds' )->willReturn( array() );
		// Из новых (20, 30) валидно только 20.
		$this->auth->expects( $this->once() )->method( 'validateItemIds' )->with( 'inf', array( 20, 30 ) )->willReturn( array( 20 ) );
		$this->manager->expects( $this->never() )->method( 'setItemIds' );
		$_POST = array( 'work_id' => '5', 'item_ids' => array( '10', '20', '30' ) );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxSaveWorkItems() );

		self::assertFalse( $r->success );
		self::assertStringContainsString( '30', (string) ( $r->payload['message'] ?? $r->payload ) );
	}

	public function test_save_work_items_does_not_revalidate_items_already_in_work(): void {
		$this->manager->method( 'get' )->willReturn( new \Inc\DTO\Course\WorkDTO(
			id          : 5,
			subjectKey  : 'inf',
			title       : 'Работа',
			workType    : \Inc\Enums\Course\WorkType::Practice,
			itemIds     : array( 10, 20 ),
			instructions: '',
			authorId    : 1,
			status      : 'draft',
		) );
		$this->auth->method( 'disallowedItemIds' )->willReturn( array() );
		$this->auth->expects( $this->never() )->method( 'validateItemIds' );
		$this->manager->method( 'setItemIds' )->willReturn( true );
		$_POST = array( 'work_id' => '5', 'item_ids' => array( '20', '10' ) );

		self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxSaveWorkItems() )->success );
	}
}
