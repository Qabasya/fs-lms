<?php

declare( strict_types=1 );

namespace Tests\Unit\Controllers\System;

use Inc\Controllers\System\CronController;
use Inc\Enums\Wp\CronHook;
use Inc\Managers\Wp\CronManager;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Services\Exam\ExamTickLock;
use Inc\Services\Exam\ExamTickService;
use Inc\Services\Profile\AdminAlertCronService;
use Inc\Services\Profile\NotificationCronService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CronControllerTest extends TestCase {

	private CronManager $cronManager;
	private ExamTickLock&MockObject $tickLock;
	private ExamTickService&MockObject $ticks;
	private CronController $controller;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_fs_test_scheduled'] = array();

		$this->cronManager = new CronManager();
		$this->tickLock    = $this->createMock( ExamTickLock::class );
		$this->ticks       = $this->createMock( ExamTickService::class );

		$this->controller = new CronController(
			$this->cronManager,
			$this->createMock( AssessmentAttemptRepository::class ),
			$this->createMock( NotificationCronService::class ),
			$this->createMock( AdminAlertCronService::class ),
			$this->tickLock,
			$this->ticks,
		);
	}

	public function test_register_adds_every_minute_interval_and_exam_hooks(): void {
		$this->controller->register();

		$schedules = $this->cronManager->filterCronSchedules( array() );

		self::assertSame( 60, $schedules['every_minute']['interval'] );
		self::assertSame( 'every_minute', $GLOBALS['_fs_test_scheduled'][ CronHook::ExamAutoExpireTick->value ]['recurrence'] );
		self::assertSame( 'every_minute', $GLOBALS['_fs_test_scheduled'][ CronHook::ExamHoldReleaseTick->value ]['recurrence'] );
	}

	public function test_hold_release_tick_runs_under_its_own_lock_and_delegates(): void {
		$this->tickLock->expects( self::once() )->method( 'run' )->willReturnCallback(
			function ( string $name, callable $fn ): bool {
				self::assertSame( CronController::EXAM_HOLD_RELEASE_LOCK, $name );
				$fn();
				return true;
			}
		);
		$this->ticks->expects( self::once() )->method( 'releaseHolds' )->willReturn( 0 );

		$this->controller->handleExamHoldReleaseTick();
	}

	public function test_register_keeps_notifications_tick_on_fifteen_minutes(): void {
		$this->controller->register();

		self::assertSame( 'every_15_minutes', $GLOBALS['_fs_test_scheduled'][ CronHook::NotificationsTick->value ]['recurrence'] );
	}

	public function test_exam_tick_runs_under_named_lock_and_delegates_to_service(): void {
		$this->tickLock->expects( self::once() )->method( 'run' )->willReturnCallback(
			function ( string $name, callable $fn ): bool {
				self::assertSame( CronController::EXAM_AUTO_EXPIRE_LOCK, $name );
				$fn();
				return true;
			}
		);
		$this->ticks->expects( self::once() )->method( 'autoExpireTick' )->willReturn( array( 'expired' => 0, 'missed' => 0 ) );

		$this->controller->handleExamAutoExpireTick();
	}
}
