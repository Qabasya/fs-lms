<?php

declare( strict_types=1 );

namespace Unit\Callbacks\Task;

use Inc\Callbacks\Task\TaskAttemptCallbacks;
use Inc\Services\Course\GroupAccessGuard;
use Inc\Services\Course\TaskAttemptReportService;
use Inc\Services\Group\ProgramCompositionService;
use PHPUnit\Framework\TestCase;
use Tests\Support\ProgramRowFixtures;

/**
 * История решений задач занятия (экран «Активность»): доступ к группе и
 * делегирование в отчёт.
 */
class TaskAttemptCallbacksTest extends TestCase {

	use ProgramRowFixtures;

	private TaskAttemptReportService  $report;
	private GroupAccessGuard          $guard;
	private ProgramCompositionService $program;
	private TaskAttemptCallbacks      $cb;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_ajax();
		$this->report  = $this->createMock( TaskAttemptReportService::class );
		$this->guard   = $this->createMock( GroupAccessGuard::class );
		$this->program = $this->createMock( ProgramCompositionService::class );

		$this->cb = new TaskAttemptCallbacks( $this->report, $this->guard, $this->program );
	}

	public function test_returns_report_for_lesson_group(): void {
		$this->program->method( 'getProgramRow' )->willReturn( $this->programRow() );
		$this->guard->method( 'canManage' )->willReturn( true );
		// groupId берётся из строки программы, а не из запроса.
		$this->report->expects( $this->once() )
			->method( 'forLesson' )
			->with( 5, 42 )
			->willReturn( array( 'steps' => array( array( 'step_key' => 'a' ) ) ) );

		$_POST = array( 'group_lesson_id' => '42' );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxGetTaskAttempts() );

		self::assertTrue( $r->success );
		self::assertCount( 1, $r->payload['steps'] );
	}

	public function test_denied_when_not_manager(): void {
		$this->program->method( 'getProgramRow' )->willReturn( $this->programRow() );
		$this->guard->method( 'canManage' )->willReturn( false );
		$this->report->expects( $this->never() )->method( 'forLesson' );

		$_POST = array( 'group_lesson_id' => '42' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxGetTaskAttempts() )->success );
	}

	public function test_denied_when_lesson_missing(): void {
		$this->program->method( 'getProgramRow' )->willReturn( null );
		$this->report->expects( $this->never() )->method( 'forLesson' );

		$_POST = array( 'group_lesson_id' => '404' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxGetTaskAttempts() )->success );
	}

	public function test_missing_param_errors(): void {
		$this->report->expects( $this->never() )->method( 'forLesson' );
		$_POST = array();

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxGetTaskAttempts() )->success );
	}
}
