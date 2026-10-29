<?php

declare( strict_types=1 );

namespace Unit\Modules\Masterclass;

use Inc\Managers\Wp\CronManager;
use Inc\Modules\Masterclass\Controllers\MasterclassCronController;
use Inc\Modules\Masterclass\Services\MasterclassLifecycleService;
use Inc\Repositories\WPDBRepositories\ExamLockRepository;
use Inc\Services\Exam\ExamTickLock;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Тик модуля: расписание раз в 15 минут, тело под замком (параллельный тик не идёт), выключение снимает событие.
 */
class MasterclassCronControllerTest extends TestCase {

	private ExamLockRepository&MockObject $locks;
	private MasterclassLifecycleService&MockObject $lifecycle;
	private MasterclassCronController $controller;

	protected function setUp(): void {
		parent::setUp();
		unset( $GLOBALS['_fs_test_scheduled'] );

		$this->locks      = $this->createMock( ExamLockRepository::class );
		$this->lifecycle  = $this->createMock( MasterclassLifecycleService::class );
		$this->controller = new MasterclassCronController( new CronManager(), new ExamTickLock( $this->locks ), $this->lifecycle );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_fs_test_scheduled'] );
		parent::tearDown();
	}

	public function test_registering_schedules_the_tick_every_fifteen_minutes(): void {
		$this->controller->register();

		self::assertSame( 'every_15_minutes', $GLOBALS['_fs_test_scheduled'][ MasterclassCronController::TICK_HOOK ]['recurrence'] );
	}

	public function test_the_tick_runs_the_lifecycle_under_the_lock(): void {
		$this->locks->expects( self::once() )->method( 'acquire' )->with( MasterclassCronController::LOCK )->willReturn( true );
		$this->locks->expects( self::once() )->method( 'release' )->with( MasterclassCronController::LOCK );
		$this->lifecycle->expects( self::once() )->method( 'tick' );

		$this->controller->onTick();
	}

	public function test_a_parallel_tick_does_not_run(): void {
		$this->locks->method( 'acquire' )->willReturn( false );
		$this->locks->expects( self::never() )->method( 'release' );
		$this->lifecycle->expects( self::never() )->method( 'tick' );

		$this->controller->onTick();
	}

	public function test_a_failing_tick_releases_the_lock_and_does_not_break_cron(): void {
		$this->locks->method( 'acquire' )->willReturn( true );
		$this->locks->expects( self::once() )->method( 'release' );
		$this->lifecycle->method( 'tick' )->willThrowException( new \RuntimeException( 'db down' ) );

		$this->controller->onTick();

		$this->addToAssertionCount( 1 );
	}

	public function test_the_hook_name_is_the_modules_own(): void {
		self::assertSame( 'fs_lms_masterclass_tick', MasterclassCronController::TICK_HOOK );
	}
}
