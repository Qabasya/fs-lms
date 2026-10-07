<?php

declare( strict_types=1 );

namespace Unit\Callbacks\Course;

use Inc\Callbacks\Course\CloneCallbacks;
use Inc\Enums\Access\Capability;
use Inc\Enums\Log\ErrorCode;
use Inc\Managers\Subject\TaskManager;
use Inc\Services\Course\ContentCloneService;
use PHPUnit\Framework\TestCase;

/**
 * Покрытие AJAX-коллбеков дублирования. Регрессия: класс звал `authorize()` /
 * `requireInt()` без подключённых трейтов и падал фаталом на любом запросе —
 * «Дублировать» не работало ни для курса, ни для урока, работы и контрольной.
 */
class CloneCallbacksTest extends TestCase {

	private CloneCallbacks $callbacks;
	private $cloneService;
	private $taskManager;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_ajax();
		$GLOBALS['_fs_test_actions'] = array();
		$this->cloneService          = $this->createMock( ContentCloneService::class );
		$this->taskManager           = $this->createMock( TaskManager::class );
		$this->callbacks             = new CloneCallbacks( $this->cloneService, $this->taskManager );
	}

	public function test_clone_course_defaults_to_shallow(): void {
		$this->cloneService->expects( $this->once() )->method( 'cloneCourse' )->with( 7, 'shallow' )->willReturn( 70 );
		$_POST = array( 'course_id' => '7' );

		$r = fs_test_capture_json( fn() => $this->callbacks->ajaxCloneCourse() );

		self::assertTrue( $r->success );
		self::assertSame( 70, $r->payload['id'] );
	}

	public function test_clone_course_passes_deep_mode(): void {
		$this->cloneService->expects( $this->once() )->method( 'cloneCourse' )->with( 7, 'deep' )->willReturn( 71 );
		$_POST = array( 'course_id' => '7', 'mode' => 'deep' );

		self::assertSame( 71, fs_test_capture_json( fn() => $this->callbacks->ajaxCloneCourse() )->payload['id'] );
	}

	public function test_clone_course_ignores_unknown_mode(): void {
		$this->cloneService->expects( $this->once() )->method( 'cloneCourse' )->with( 7, 'shallow' )->willReturn( 72 );
		$_POST = array( 'course_id' => '7', 'mode' => 'everything' );

		self::assertTrue( fs_test_capture_json( fn() => $this->callbacks->ajaxCloneCourse() )->success );
	}

	public function test_clone_course_reports_service_failure(): void {
		$this->cloneService->expects( $this->once() )->method( 'cloneCourse' )->willReturn( 0 );
		$_POST = array( 'course_id' => '7' );

		$r = fs_test_capture_json( fn() => $this->callbacks->ajaxCloneCourse() );

		self::assertFalse( $r->success );
		self::assertSame( 'Не удалось клонировать курс.', $r->payload );
	}

	public function test_clone_course_requires_id_and_logs_the_field(): void {
		$this->cloneService->expects( $this->never() )->method( 'cloneCourse' );
		$_POST = array();

		$r = fs_test_capture_json( fn() => $this->callbacks->ajaxCloneCourse() );

		self::assertFalse( $r->success );
		self::assertSame( array( 'field' => 'course_id' ), $this->loggedError()['context'] );
	}

	public function test_clone_denied_without_capability_and_logged(): void {
		$GLOBALS['_fs_test_can'] = false;
		$this->cloneService->expects( $this->never() )->method( 'cloneCourse' );
		$_POST = array( 'course_id' => '7' );

		$r = fs_test_capture_json( fn() => $this->callbacks->ajaxCloneCourse() );

		self::assertFalse( $r->success );
		self::assertSame( array( 'capability' => Capability::AuthorLmsCourses->value ), $this->loggedError()['context'] );
	}

	public function test_clone_lesson_work_and_assessment_return_copy_id(): void {
		$this->cloneService->expects( $this->once() )->method( 'cloneLesson' )->with( 3 )->willReturn( 30 );
		$this->cloneService->expects( $this->once() )->method( 'cloneWork' )->with( 4 )->willReturn( 40 );
		$this->cloneService->expects( $this->once() )->method( 'cloneAssessment' )->with( 5 )->willReturn( 50 );

		$_POST = array( 'lesson_id' => '3' );
		self::assertSame( 30, fs_test_capture_json( fn() => $this->callbacks->ajaxCloneLesson() )->payload['id'] );

		$_POST = array( 'work_id' => '4' );
		self::assertSame( 40, fs_test_capture_json( fn() => $this->callbacks->ajaxCloneWork() )->payload['id'] );

		$_POST = array( 'assessment_id' => '5' );
		self::assertSame( 50, fs_test_capture_json( fn() => $this->callbacks->ajaxCloneAssessment() )->payload['id'] );
	}

	public function test_clone_task_returns_copy_id(): void {
		$this->taskManager->expects( $this->once() )->method( 'duplicate' )->with( 6 )->willReturn( 60 );
		$_POST = array( 'task_id' => '6' );

		self::assertSame( 60, fs_test_capture_json( fn() => $this->callbacks->ajaxCloneTask() )->payload['id'] );
	}

	public function test_clone_task_reports_why_it_cannot_be_copied(): void {
		$this->taskManager->method( 'duplicate' )->willThrowException( new \RuntimeException( 'У задания не выбран номер задания.' ) );
		$_POST = array( 'task_id' => '6' );

		$r = fs_test_capture_json( fn() => $this->callbacks->ajaxCloneTask() );

		self::assertFalse( $r->success );
		self::assertSame( 'У задания не выбран номер задания.', $r->payload );
	}

	public function test_fork_lesson_for_group_returns_fork_id(): void {
		$this->cloneService->expects( $this->once() )->method( 'forkLessonForGroup' )->with( 2, 9 )->willReturn( 90 );
		$_POST = array( 'group_id' => '2', 'group_lesson_id' => '9' );

		self::assertSame( 90, fs_test_capture_json( fn() => $this->callbacks->ajaxForkLessonForGroup() )->payload['id'] );
	}

	/**
	 * Единственная запись, ушедшая в журнал «Ошибки» (хук {@see ErrorCode::HOOK}).
	 *
	 * @return array{code: ErrorCode, message: string, ref: string, context: array<string, mixed>}
	 */
	private function loggedError(): array {
		$calls = array_values( array_filter(
			$GLOBALS['_fs_test_actions'],
			static fn( array $call ): bool => ErrorCode::HOOK === $call['hook']
		) );

		self::assertCount( 1, $calls );
		self::assertSame( ErrorCode::Ajax, $calls[0]['args'][0] );
		self::assertMatchesRegularExpression( '/^[0-9A-F]{6}$/', $calls[0]['args'][2] );

		return array(
			'code'    => $calls[0]['args'][0],
			'message' => $calls[0]['args'][1],
			'ref'     => $calls[0]['args'][2],
			'context' => $calls[0]['args'][3],
		);
	}
}
