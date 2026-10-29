<?php

declare( strict_types=1 );

namespace Unit\Modules\Masterclass;

use Inc\Contracts\ClockInterface;
use Inc\Modules\Masterclass\Config\MasterclassConfig;
use Inc\Modules\Masterclass\DTO\MasterclassAccountDTO;
use Inc\Modules\Masterclass\DTO\MasterclassDTO;
use Inc\Modules\Masterclass\Enums\AccountDeleteReason;
use Inc\Modules\Masterclass\Enums\MasterclassLogAction;
use Inc\Modules\Masterclass\Repositories\MasterclassAccountRepository;
use Inc\Modules\Masterclass\Repositories\MasterclassLogRepository;
use Inc\Modules\Masterclass\Repositories\MasterclassRepository;
use Inc\Modules\Masterclass\Services\MasterclassAccountService;
use Inc\Modules\Masterclass\Services\MasterclassLifecycleService;
use Inc\Modules\Masterclass\Services\MasterclassLogWriter;
use Inc\Services\Deletion\DeleteGroupEvent;
use Inc\Services\Deletion\DeletionEventDispatcher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Тик и удаление: журнал заморозки, удаление неиспользованных и просроченных порциями с общим бюджетом, снос группы,
 * подчистка зависших строк и старого журнала, Ш3 (`fs_lms_group_deleting`) и `deleted_user`.
 */
class MasterclassLifecycleServiceTest extends TestCase {

	private const NOW = '2026-11-10 12:00:00';

	private MasterclassRepository&MockObject $masterclasses;
	private MasterclassAccountRepository&MockObject $accounts;
	private MasterclassAccountService&MockObject $accountService;
	private MasterclassLogRepository&MockObject $logRepo;
	private DeletionEventDispatcher&MockObject $deletion;
	private MasterclassLogWriter&MockObject $log;
	private MasterclassLifecycleService $service;

	/** @var list<string> */
	private array $calls = array();

	/** @var list<array{0: MasterclassLogAction, 1: array<string, mixed>}> */
	private array $logged = array();

	protected function setUp(): void {
		$this->calls          = array();
		$this->logged         = array();
		$this->masterclasses  = $this->createMock( MasterclassRepository::class );
		$this->accounts       = $this->createMock( MasterclassAccountRepository::class );
		$this->accountService = $this->createMock( MasterclassAccountService::class );
		$this->logRepo        = $this->createMock( MasterclassLogRepository::class );
		$this->deletion       = $this->createMock( DeletionEventDispatcher::class );
		$this->log            = $this->createMock( MasterclassLogWriter::class );

		$this->log->method( 'write' )->willReturnCallback(
			function ( MasterclassLogAction $action, array $data = array() ): void {
				$this->logged[] = array( $action, $data );
			}
		);

		$config = $this->createStub( MasterclassConfig::class );
		$config->method( 'logRetentionDays' )->willReturn( 365 );

		$clock = $this->createStub( ClockInterface::class );
		$clock->method( 'now' )->willReturn( self::NOW );

		$this->service = new MasterclassLifecycleService(
			$this->masterclasses,
			$this->accounts,
			$this->accountService,
			$this->logRepo,
			$config,
			$this->deletion,
			$this->log,
			$clock
		);
	}

	private function masterclass( array $over = array() ): MasterclassDTO {
		return MasterclassDTO::fromArray( array_merge(
			array(
				'id'              => 7,
				'group_id'        => 31,
				'group_lesson_id' => 41,
				'starts_at'       => '2026-11-01 06:00:00',
				'ends_at'         => '2026-11-01 11:00:00',
				'freeze_at'       => '2026-11-08 11:00:00',
				'unused_purge_at' => '2026-11-05 11:00:00',
				'purge_at'        => '2026-11-20 11:00:00',
			),
			$over
		) );
	}

	private function account( int $id, array $over = array() ): MasterclassAccountDTO {
		return MasterclassAccountDTO::fromArray( array_merge(
			array(
				'id'                => $id,
				'masterclass_id'    => 7,
				'user_id'           => 1000 + $id,
				'person_id'         => 2000 + $id,
				'student_record_id' => 3000 + $id,
				'state'             => 'active',
			),
			$over
		) );
	}

	/** @return list<string> Действия журнала по порядку. */
	private function loggedActions(): array {
		return array_map( static fn ( array $l ): string => $l[0]->value, $this->logged );
	}

	// ── logFreezes ──────────────────────────────────────────────────────

	public function test_freeze_is_logged_once_by_the_call_that_marked_it(): void {
		$this->masterclasses->method( 'listDueForFreeze' )->with( self::NOW, 100 )->willReturn( array( $this->masterclass( array( 'id' => 7 ) ), $this->masterclass( array( 'id' => 8 ) ) ) );
		$this->masterclasses->method( 'markFrozen' )->willReturnMap( array( array( 7, self::NOW, true ), array( 8, self::NOW, false ) ) );

		self::assertSame( 1, $this->service->logFreezes() );
		self::assertSame( array( 'frozen' ), $this->loggedActions() );
		self::assertSame( 7, $this->logged[0][1]['masterclass_id'] );
		self::assertSame( 'system', $this->logged[0][1]['actor_type'] );
	}

	// ── purgeUnused ─────────────────────────────────────────────────────

	public function test_unused_accounts_are_deleted_and_the_masterclass_is_marked_when_none_are_left(): void {
		$this->masterclasses->method( 'listDueForUnusedPurge' )->willReturn( array( $this->masterclass() ) );
		$this->accounts->method( 'nextUnused' )->willReturn( array( $this->account( 1 ), $this->account( 2 ) ), array() );
		$this->accountService->expects( self::exactly( 2 ) )->method( 'deleteAccount' )->with( self::isInstanceOf( MasterclassAccountDTO::class ), AccountDeleteReason::Unused, 0 );
		$this->masterclasses->expects( self::once() )->method( 'markUnusedPurged' )->with( 7, self::NOW );

		$budget = 60;

		self::assertSame( 2, $this->service->purgeUnused( $budget ) );
		self::assertSame( 58, $budget );
	}

	public function test_unused_purge_stops_at_the_budget_and_does_not_mark_the_masterclass(): void {
		$this->masterclasses->method( 'listDueForUnusedPurge' )->willReturn( array( $this->masterclass() ) );
		$this->accounts->method( 'nextUnused' )->willReturn( array( $this->account( 1 ), $this->account( 2 ) ) );
		$this->accountService->expects( self::exactly( 2 ) )->method( 'deleteAccount' );
		$this->masterclasses->expects( self::never() )->method( 'markUnusedPurged' );

		$budget = 2;

		self::assertSame( 2, $this->service->purgeUnused( $budget ) );
		self::assertSame( 0, $budget );
	}

	public function test_unused_purge_with_an_empty_budget_does_nothing(): void {
		$this->masterclasses->expects( self::never() )->method( 'listDueForUnusedPurge' );

		$budget = 0;

		self::assertSame( 0, $this->service->purgeUnused( $budget ) );
	}

	public function test_unused_purge_asks_for_no_more_than_the_budget(): void {
		$this->masterclasses->method( 'listDueForUnusedPurge' )->willReturn( array( $this->masterclass() ) );
		$this->accounts->expects( self::once() )->method( 'nextUnused' )->with( 7, 3 )->willReturn( array( $this->account( 1 ), $this->account( 2 ), $this->account( 3 ) ) );

		$budget = 3;
		$this->service->purgeUnused( $budget );
	}

	// ── purgeExpired ────────────────────────────────────────────────────

	public function test_expired_accounts_are_deleted_with_the_expired_reason(): void {
		$this->masterclasses->method( 'listDueForPurge' )->willReturn( array( $this->masterclass() ) );
		$this->accounts->method( 'nextPurgeable' )->willReturn( array( $this->account( 1 ) ), array() );
		$this->accountService->method( 'hasLiveApplication' )->willReturn( false );
		$this->accountService->expects( self::once() )->method( 'deleteAccount' )->with( self::anything(), AccountDeleteReason::Expired, 0 );

		$budget = 60;

		self::assertSame( 1, $this->service->purgeExpired( $budget ) );
		self::assertSame( 59, $budget );
	}

	public function test_requested_deletion_uses_the_requested_reason(): void {
		$this->masterclasses->method( 'listDueForPurge' )->willReturn( array( $this->masterclass( array( 'delete_requested_at' => '2026-11-10 10:00:00' ) ) ) );
		$this->accounts->method( 'nextPurgeable' )->willReturn( array( $this->account( 1 ) ), array() );
		$this->accountService->method( 'hasLiveApplication' )->willReturn( false );
		$this->accountService->expects( self::once() )->method( 'deleteAccount' )->with( self::anything(), AccountDeleteReason::Requested, 0 );

		$budget = 60;
		$this->service->purgeExpired( $budget );
	}

	public function test_an_account_with_a_live_application_is_detached_on_explicit_deletion(): void {
		$this->masterclasses->method( 'listDueForPurge' )->willReturn( array( $this->masterclass( array( 'delete_requested_at' => '2026-11-10 10:00:00' ) ) ) );
		$this->accounts->method( 'nextPurgeable' )->willReturn( array( $this->account( 1 ) ), array() );
		$this->accountService->method( 'hasLiveApplication' )->willReturn( true );
		$this->accountService->expects( self::once() )->method( 'detachAccount' )->with( self::anything(), 0 );
		$this->accountService->expects( self::never() )->method( 'deleteAccount' );

		$budget = 60;

		self::assertSame( 1, $this->service->purgeExpired( $budget ) );
	}

	public function test_an_account_with_a_live_application_is_kept_when_only_the_term_expired(): void {
		$this->masterclasses->method( 'listDueForPurge' )->willReturn( array( $this->masterclass() ) );
		$this->accounts->method( 'nextPurgeable' )->willReturn( array( $this->account( 1 ) ), array() );
		$this->accountService->method( 'hasLiveApplication' )->willReturn( true );
		$this->accountService->expects( self::never() )->method( 'detachAccount' );
		$this->accountService->expects( self::never() )->method( 'deleteAccount' );

		$budget = 60;

		self::assertSame( 0, $this->service->purgeExpired( $budget ) );
		self::assertSame( 60, $budget, 'пропуск бюджет не тратит' );
	}

	public function test_skipped_accounts_are_passed_with_a_cursor_so_they_do_not_block_the_next_ones(): void {
		$this->masterclasses->method( 'listDueForPurge' )->willReturn( array( $this->masterclass() ) );
		$this->accounts->expects( self::exactly( 2 ) )->method( 'nextPurgeable' )
			->willReturnCallback(
				fn ( int $mc, int $limit, int $after ): array => 0 === $after ? array( $this->account( 4 ) ) : array()
			);
		$this->accountService->method( 'hasLiveApplication' )->willReturn( true );

		$budget = 60;
		$this->service->purgeExpired( $budget );
	}

	public function test_expired_purge_stops_at_the_budget(): void {
		$this->masterclasses->method( 'listDueForPurge' )->willReturn( array( $this->masterclass() ) );
		$this->accounts->method( 'nextPurgeable' )->willReturn( array( $this->account( 1 ), $this->account( 2 ), $this->account( 3 ) ) );
		$this->accountService->method( 'hasLiveApplication' )->willReturn( false );
		$this->accountService->expects( self::exactly( 2 ) )->method( 'deleteAccount' );

		$budget = 2;

		self::assertSame( 2, $this->service->purgeExpired( $budget ) );
		self::assertSame( 0, $budget );
	}

	// ── dropGroups ──────────────────────────────────────────────────────

	public function test_the_group_is_dropped_when_no_account_holds_a_record(): void {
		$this->masterclasses->method( 'listDueForPurge' )->willReturn( array( $this->masterclass() ) );
		$this->accounts->method( 'countAttached' )->with( 7 )->willReturn( 0 );
		$this->deletion->expects( self::once() )->method( 'dispatch' )->with( self::callback( static fn ( $e ): bool => $e instanceof DeleteGroupEvent && 31 === $e->groupId && 0 === $e->actorId ) );
		$this->masterclasses->expects( self::once() )->method( 'markPurged' )->with( 7, self::NOW );

		self::assertSame( 1, $this->service->dropGroups() );
		self::assertSame( array( 'masterclass_purged' ), $this->loggedActions() );
	}

	public function test_the_group_lives_while_an_account_holds_a_record(): void {
		$this->masterclasses->method( 'listDueForPurge' )->willReturn( array( $this->masterclass() ) );
		$this->accounts->method( 'countAttached' )->willReturn( 1 );
		$this->deletion->expects( self::never() )->method( 'dispatch' );
		$this->masterclasses->expects( self::never() )->method( 'markPurged' );

		self::assertSame( 0, $this->service->dropGroups() );
	}

	public function test_a_masterclass_whose_group_is_already_gone_is_only_marked(): void {
		$this->masterclasses->method( 'listDueForPurge' )->willReturn( array( $this->masterclass( array( 'group_id' => null, 'group_lesson_id' => null ) ) ) );
		$this->accounts->method( 'countAttached' )->willReturn( 0 );
		$this->deletion->expects( self::never() )->method( 'dispatch' );
		$this->masterclasses->expects( self::once() )->method( 'markPurged' );

		$this->service->dropGroups();
	}

	// ── sweepStale / purgeLog ───────────────────────────────────────────

	public function test_stale_pending_numbers_are_marked_as_create_failed(): void {
		$this->accounts->method( 'listStalePending' )->with( '2026-11-10 11:00:00', 100 )->willReturn( array( $this->account( 1, array( 'user_id' => null ) ), $this->account( 2, array( 'user_id' => null ) ) ) );
		$this->accounts->expects( self::exactly( 2 ) )->method( 'markDeleted' )->with( self::anything(), AccountDeleteReason::CreateFailed, self::NOW )->willReturn( true );

		self::assertSame( 2, $this->service->sweepStale() );
	}

	public function test_the_log_is_cut_by_the_retention_period(): void {
		$this->logRepo->expects( self::once() )->method( 'purgeOlderThan' )->with( '2025-11-10 12:00:00' )->willReturn( 17 );

		self::assertSame( 17, $this->service->purgeLog() );
	}

	// ── tick ────────────────────────────────────────────────────────────

	public function test_a_tick_runs_every_step_and_reports_counts(): void {
		$this->masterclasses->method( 'listDueForFreeze' )->willReturn( array() );
		$this->masterclasses->method( 'listDueForUnusedPurge' )->willReturn( array() );
		$this->masterclasses->method( 'listDueForPurge' )->willReturn( array() );
		$this->accounts->method( 'listStalePending' )->willReturn( array() );
		$this->logRepo->method( 'purgeOlderThan' )->willReturn( 3 );

		self::assertSame(
			array( 'frozen' => 0, 'unused' => 0, 'purged' => 0, 'groups' => 0, 'stale' => 0, 'log' => 3 ),
			$this->service->tick()
		);
	}

	public function test_a_failing_step_does_not_stop_the_others(): void {
		$this->masterclasses->method( 'listDueForFreeze' )->willThrowException( new \RuntimeException( 'db down' ) );
		$this->masterclasses->method( 'listDueForUnusedPurge' )->willReturn( array() );
		$this->masterclasses->method( 'listDueForPurge' )->willReturn( array() );
		$this->accounts->method( 'listStalePending' )->willReturn( array() );
		$this->logRepo->expects( self::once() )->method( 'purgeOlderThan' )->willReturn( 5 );

		$result = $this->service->tick();

		self::assertSame( 0, $result['frozen'] );
		self::assertSame( 5, $result['log'] );
	}

	public function test_the_budget_is_shared_between_unused_and_expired_deletions(): void {
		$sixty = array();
		for ( $i = 1; $i <= 60; $i++ ) {
			$sixty[] = $this->account( $i );
		}

		$this->masterclasses->method( 'listDueForFreeze' )->willReturn( array() );
		$this->masterclasses->method( 'listDueForUnusedPurge' )->willReturn( array( $this->masterclass() ) );
		$this->masterclasses->method( 'listDueForPurge' )->willReturn( array( $this->masterclass() ) );
		$this->accounts->method( 'nextUnused' )->willReturn( $sixty );
		$this->accounts->expects( self::never() )->method( 'nextPurgeable' );
		$this->accounts->method( 'listStalePending' )->willReturn( array() );
		$this->accounts->method( 'countAttached' )->willReturn( 5 );
		$this->accountService->expects( self::exactly( 60 ) )->method( 'deleteAccount' );

		$result = $this->service->tick();

		self::assertSame( 60, $result['unused'] );
		self::assertSame( 0, $result['purged'], 'бюджет исчерпан шагом 2 — шаг 3 ничего не удаляет' );
	}

	// ── Ш3 onGroupDeleting ──────────────────────────────────────────────

	public function test_deleting_a_group_removes_its_accounts_and_marks_the_masterclass(): void {
		$this->masterclasses->method( 'findByGroupId' )->with( 31 )->willReturn( $this->masterclass() );
		$this->accounts->method( 'nextPurgeable' )->willReturn( array( $this->account( 1 ), $this->account( 2 ) ), array() );
		$this->accountService->method( 'hasLiveApplication' )->willReturnCallback( static fn ( MasterclassAccountDTO $a ): bool => 2 === $a->id );
		$this->accountService->expects( self::once() )->method( 'deleteAccount' )->with( self::callback( static fn ( MasterclassAccountDTO $a ): bool => 1 === $a->id ), AccountDeleteReason::GroupDeleted, 501 );
		$this->accountService->expects( self::once() )->method( 'detachAccount' )->with( self::callback( static fn ( MasterclassAccountDTO $a ): bool => 2 === $a->id ), 501 );
		$this->masterclasses->expects( self::once() )->method( 'markPurged' )->with( 7, self::NOW );

		$this->service->onGroupDeleting( 31, 501 );

		self::assertSame( array( 'masterclass_purged' ), $this->loggedActions() );
	}

	public function test_deleting_a_foreign_group_does_nothing(): void {
		$this->masterclasses->method( 'findByGroupId' )->willReturn( null );
		$this->accounts->expects( self::never() )->method( 'nextPurgeable' );
		$this->masterclasses->expects( self::never() )->method( 'markPurged' );

		$this->service->onGroupDeleting( 99, 501 );
	}

	public function test_an_already_purged_masterclass_is_not_logged_twice(): void {
		$this->masterclasses->method( 'findByGroupId' )->willReturn( $this->masterclass( array( 'purged_at' => '2026-11-10 11:00:00' ) ) );
		$this->accounts->method( 'nextPurgeable' )->willReturn( array() );

		$this->service->onGroupDeleting( 31, 0 );

		self::assertSame( array(), $this->loggedActions() );
	}

	// ── deleted_user ────────────────────────────────────────────────────

	public function test_a_manually_deleted_user_marks_the_account_and_is_logged(): void {
		$this->accounts->method( 'markDeletedByUserId' )->with( 1005, AccountDeleteReason::UserDeleted, self::NOW )->willReturn( 5 );
		$this->accounts->method( 'find' )->with( 5 )->willReturn( $this->account( 5 ) );

		$this->service->onUserDeleted( 1005 );

		self::assertSame( array( 'account_deleted' ), $this->loggedActions() );
		self::assertSame( 5, $this->logged[0][1]['account_id'] );
		self::assertSame( 7, $this->logged[0][1]['masterclass_id'] );
	}

	public function test_an_already_marked_or_foreign_user_is_not_logged(): void {
		$this->accounts->method( 'markDeletedByUserId' )->willReturn( null );

		$this->service->onUserDeleted( 1005 );

		self::assertSame( array(), $this->loggedActions() );
	}
}
