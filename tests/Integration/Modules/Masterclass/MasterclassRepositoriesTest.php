<?php

declare( strict_types=1 );

namespace Integration\Modules\Masterclass;

use FakeWpdb;
use Inc\Modules\Masterclass\DTO\MasterclassLogInputDTO;
use Inc\Modules\Masterclass\Enums\AccountDeleteReason;
use Inc\Modules\Masterclass\Enums\MasterclassAccountState;
use Inc\Modules\Masterclass\Enums\MasterclassLogAction;
use Inc\Modules\Masterclass\Repositories\MasterclassAccountRepository;
use Inc\Modules\Masterclass\Repositories\MasterclassLogRepository;
use Inc\Modules\Masterclass\Repositories\MasterclassRepository;
use PHPUnit\Framework\TestCase;

/**
 * Репозитории модуля на дубле wpdb: какой SQL они строят и как собирают DTO. Поведение на настоящей MariaDB
 * (условные UPDATE, уникальные номера, воронка) проверяет `tests/stand/masterclass-schema-check.php`.
 */
class MasterclassRepositoriesTest extends TestCase {

	private FakeWpdb $wpdb;

	protected function setUp(): void {
		parent::setUp();
		$this->wpdb = new FakeWpdb();
	}

	/** @param array<string, mixed> $over */
	private function masterclassRow( array $over = array() ): array {
		return array_merge(
			array(
				'id'              => '7',
				'school_name'     => 'Школа №5',
				'subject_key'     => 'inf',
				'lesson_id'       => '321',
				'group_id'        => '41',
				'group_lesson_id' => '900',
				'teacher_user_id' => '12',
				'starts_at'       => '2026-11-10 06:00:00',
				'ends_at'         => '2026-11-10 11:00:00',
				'freeze_at'       => '2026-11-24 11:00:00',
				'unused_purge_at' => '2026-11-17 11:00:00',
				'purge_at'        => '2026-12-24 11:00:00',
				'created_at'      => '2026-11-01 10:00:00',
				'updated_at'      => '2026-11-01 10:00:00',
			),
			$over
		);
	}

	/** @param array<string, mixed> $over */
	private function accountRow( array $over = array() ): array {
		return array_merge(
			array(
				'id'             => '15',
				'masterclass_id' => '7',
				'user_id'        => '501',
				'state'          => 'active',
				'created_at'     => '2026-11-01 10:00:00',
				'updated_at'     => '2026-11-01 10:00:00',
			),
			$over
		);
	}

	// ── MasterclassRepository ─────────────────────────────────────────────────────

	public function test_masterclass_find_reads_the_module_table(): void {
		$this->wpdb->queueRow( $this->masterclassRow() );

		$dto = ( new MasterclassRepository( $this->wpdb ) )->find( 7 );

		self::assertStringContainsString( '`wp_fs_lms_masterclasses`', $this->wpdb->lastQuery() );
		self::assertStringContainsString( 'WHERE id = 7', $this->wpdb->lastQuery() );
		self::assertSame( 41, $dto?->groupId );
		self::assertSame( 'Школа №5', $dto?->schoolName );
	}

	public function test_masterclass_find_returns_null_when_there_is_no_row(): void {
		self::assertNull( ( new MasterclassRepository( $this->wpdb ) )->find( 99 ) );
		self::assertNull( ( new MasterclassRepository( $this->wpdb ) )->findByGroupId( 99 ) );
	}

	public function test_masterclass_find_by_group(): void {
		$this->wpdb->queueRow( $this->masterclassRow() );

		( new MasterclassRepository( $this->wpdb ) )->findByGroupId( 41 );

		self::assertStringContainsString( 'WHERE group_id = 41', $this->wpdb->lastQuery() );
	}

	public function test_masterclass_page_sorts_new_first_and_counts(): void {
		$this->wpdb->queueResults( array( $this->masterclassRow(), $this->masterclassRow( array( 'id' => '6' ) ) ) );
		$this->wpdb->queueVar( '12' );

		$page = ( new MasterclassRepository( $this->wpdb ) )->page( 3, 20 );

		self::assertStringContainsString( 'ORDER BY starts_at DESC, id DESC LIMIT 20 OFFSET 40', $this->wpdb->queries[0] );
		self::assertCount( 2, $page['rows'] );
		self::assertSame( 12, $page['total'] );
	}

	public function test_masterclass_insert_returns_the_new_id_and_writes_to_the_module_table(): void {
		$this->wpdb->insert_id = 33;

		$id = ( new MasterclassRepository( $this->wpdb ) )->insert( array( 'school_name' => 'Школа', 'group_id' => 41 ) );

		self::assertSame( 33, $id );
		self::assertSame( 'wp_fs_lms_masterclasses', $this->wpdb->inserts[0]['table'] );
		self::assertSame( 41, $this->wpdb->inserts[0]['data']['group_id'] );
	}

	public function test_masterclass_update_targets_the_row_by_id(): void {
		( new MasterclassRepository( $this->wpdb ) )->update( 7, array( 'school_name' => 'Новая', 'frozen_at' => null ) );

		self::assertSame( 'wp_fs_lms_masterclasses', $this->wpdb->updates[0]['table'] );
		self::assertSame( array( 'id' => 7 ), $this->wpdb->updates[0]['where'] );
		self::assertNull( $this->wpdb->updates[0]['data']['frozen_at'] );
	}

	public function test_active_group_ids_come_from_the_table_without_nulls(): void {
		$this->wpdb->queueCol( array( '41', '52' ) );

		$ids = ( new MasterclassRepository( $this->wpdb ) )->activeGroupIds();

		self::assertSame( array( 41, 52 ), $ids );
		self::assertStringContainsString( 'group_id IS NOT NULL', $this->wpdb->lastQuery() );
	}

	public function test_running_for_uses_the_lead_before_the_start(): void {
		$this->wpdb->queueResults( array( $this->masterclassRow() ) );

		$rows = ( new MasterclassRepository( $this->wpdb ) )->runningFor( '2026-11-10 05:30:00', 60 );

		$sql = $this->wpdb->lastQuery();
		self::assertCount( 1, $rows );
		self::assertStringContainsString( "ends_at >= '2026-11-10 05:30:00'", $sql );
		self::assertStringContainsString( "DATE_SUB( starts_at, INTERVAL 60 MINUTE ) <= '2026-11-10 05:30:00'", $sql );
		self::assertStringContainsString( 'purged_at IS NULL', $sql );
	}

	public function test_due_for_freeze_takes_only_unprocessed(): void {
		( new MasterclassRepository( $this->wpdb ) )->listDueForFreeze( '2026-11-24 11:15:00', 50 );

		$sql = $this->wpdb->lastQuery();
		self::assertStringContainsString( "freeze_at <= '2026-11-24 11:15:00' AND frozen_at IS NULL AND purged_at IS NULL", $sql );
		self::assertStringContainsString( 'LIMIT 50', $sql );
	}

	public function test_due_for_unused_purge_takes_only_unprocessed(): void {
		( new MasterclassRepository( $this->wpdb ) )->listDueForUnusedPurge( '2026-11-17 11:15:00', 10 );

		self::assertStringContainsString(
			"unused_purge_at <= '2026-11-17 11:15:00' AND unused_purged_at IS NULL AND purged_at IS NULL",
			$this->wpdb->lastQuery()
		);
	}

	public function test_due_for_purge_includes_requested_deletes_and_orders_them_by_request_time(): void {
		( new MasterclassRepository( $this->wpdb ) )->listDueForPurge( '2026-12-24 11:15:00', 5 );

		$sql = $this->wpdb->lastQuery();
		self::assertStringContainsString( "( purge_at <= '2026-12-24 11:15:00' OR delete_requested_at IS NOT NULL ) AND purged_at IS NULL", $sql );
		self::assertStringContainsString( 'ORDER BY COALESCE( delete_requested_at, purge_at ) ASC, id ASC', $sql );
	}

	public function test_mark_frozen_reports_whether_this_call_set_the_mark(): void {
		$repository = new MasterclassRepository( $this->wpdb );

		$this->wpdb->queueQuery( 1 );
		self::assertTrue( $repository->markFrozen( 7, '2026-11-24 11:15:00' ) );

		$this->wpdb->queueQuery( 0 );
		self::assertFalse( $repository->markFrozen( 7, '2026-11-24 11:16:00' ), 'отметка уже стояла' );

		self::assertStringContainsString( 'frozen_at IS NULL AND purged_at IS NULL', $this->wpdb->lastQuery() );
	}

	public function test_mark_purged_clears_the_references_to_deleted_group_and_lesson(): void {
		( new MasterclassRepository( $this->wpdb ) )->markPurged( 7, '2026-12-24 11:15:00' );

		self::assertStringContainsString( "purged_at = '2026-12-24 11:15:00', group_id = NULL, group_lesson_id = NULL", $this->wpdb->lastQuery() );
		self::assertStringContainsString( 'purged_at IS NULL', $this->wpdb->lastQuery(), 'повторная очистка не двигает отметку' );
	}

	public function test_mark_delete_requested_does_not_move_an_earlier_request(): void {
		( new MasterclassRepository( $this->wpdb ) )->markDeleteRequested( 7, '2026-11-11 09:00:00' );

		self::assertStringContainsString( 'delete_requested_at IS NULL', $this->wpdb->lastQuery() );
	}

	public function test_mark_unused_purged_is_conditional(): void {
		( new MasterclassRepository( $this->wpdb ) )->markUnusedPurged( 7, '2026-11-17 11:15:00' );

		self::assertStringContainsString( 'unused_purged_at IS NULL', $this->wpdb->lastQuery() );
	}

	// ── MasterclassAccountRepository ──────────────────────────────────────────────

	public function test_account_finders_include_deleted_rows(): void {
		$repository = new MasterclassAccountRepository( $this->wpdb );

		$this->wpdb->queueRow( $this->accountRow( array( 'deleted_at' => '2026-11-20 10:00:00', 'delete_reason' => 'unused' ) ) );
		$byUser = $repository->findByUserId( 501 );
		self::assertTrue( $byUser?->isDeleted(), 'удалённый аккаунт возвращается — проверяет вызывающий' );
		self::assertStringNotContainsString( 'deleted_at IS NULL', $this->wpdb->lastQuery() );
		self::assertStringContainsString( 'WHERE user_id = 501', $this->wpdb->lastQuery() );

		$this->wpdb->queueRow( $this->accountRow() );
		$repository->find( 15 );
		self::assertStringContainsString( 'WHERE id = 15', $this->wpdb->lastQuery() );

		$this->wpdb->queueRow( $this->accountRow() );
		$repository->findByPersonId( 88 );
		self::assertStringContainsString( 'WHERE person_id = 88', $this->wpdb->lastQuery() );
	}

	public function test_list_by_masterclass_is_ordered_by_number(): void {
		$this->wpdb->queueResults( array( $this->accountRow(), $this->accountRow( array( 'id' => '16' ) ) ) );

		$rows = ( new MasterclassAccountRepository( $this->wpdb ) )->listByMasterclass( 7 );

		self::assertSame( array( 15, 16 ), array_map( static fn ( $a ): int => $a->id, $rows ) );
		self::assertStringContainsString( 'ORDER BY id ASC', $this->wpdb->lastQuery() );
	}

	public function test_live_user_ids_skip_accounts_without_user_and_deleted_ones(): void {
		$this->wpdb->queueCol( array( '501', '502' ) );

		$ids = ( new MasterclassAccountRepository( $this->wpdb ) )->liveUserIds( 7 );

		self::assertSame( array( 501, 502 ), $ids );
		self::assertStringContainsString( 'user_id IS NOT NULL AND deleted_at IS NULL', $this->wpdb->lastQuery() );
	}

	public function test_page_by_masterclass_hides_deleted_unless_asked(): void {
		$repository = new MasterclassAccountRepository( $this->wpdb );

		$this->wpdb->queueResults( array( $this->accountRow() ) );
		$this->wpdb->queueVar( '51' );
		$page = $repository->pageByMasterclass( 7, 2, 50, false );
		self::assertSame( 51, $page['total'] );
		self::assertCount( 1, $page['rows'] );
		self::assertStringContainsString( 'AND deleted_at IS NULL ORDER BY id ASC LIMIT 50 OFFSET 50', $this->wpdb->queries[0] );
		self::assertStringContainsString( 'AND deleted_at IS NULL', $this->wpdb->queries[1], 'счётчик считает то же, что и список' );

		$this->wpdb->queries = array();
		$this->wpdb->queueResults( array() );
		$this->wpdb->queueVar( '60' );
		$repository->pageByMasterclass( 7, 1, 50, true );
		self::assertStringNotContainsString( 'deleted_at IS NULL', $this->wpdb->queries[0] );
		self::assertStringNotContainsString( 'deleted_at IS NULL', $this->wpdb->queries[1] );
	}

	public function test_insert_pending_takes_a_number_with_explicit_utc_times(): void {
		$this->wpdb->insert_id = 15;

		$number = ( new MasterclassAccountRepository( $this->wpdb ) )->insertPending( 7, '2026-11-01 10:00:00' );

		self::assertSame( 15, $number, 'номер = id вставленной строки' );
		self::assertSame( 'wp_fs_lms_masterclass_accounts', $this->wpdb->inserts[0]['table'] );
		self::assertSame(
			array(
				'masterclass_id' => 7,
				'state'          => 'active',
				'created_at'     => '2026-11-01 10:00:00',
				'updated_at'     => '2026-11-01 10:00:00',
			),
			$this->wpdb->inserts[0]['data']
		);
	}

	public function test_attach_binds_user_person_and_record(): void {
		( new MasterclassAccountRepository( $this->wpdb ) )->attach( 15, 501, 88, 300 );

		self::assertStringContainsString( 'user_id = 501, person_id = 88, student_record_id = 300', $this->wpdb->lastQuery() );
		self::assertStringContainsString( 'WHERE id = 15', $this->wpdb->lastQuery() );
	}

	public function test_set_state_keeps_the_application_unless_given(): void {
		$repository = new MasterclassAccountRepository( $this->wpdb );

		$repository->setState( 15, MasterclassAccountState::Enrolled );
		self::assertStringContainsString( "state = 'enrolled'", $this->wpdb->lastQuery() );
		self::assertStringNotContainsString( 'application_id', $this->wpdb->lastQuery() );

		$repository->setState( 15, MasterclassAccountState::Applied, 44 );
		self::assertStringContainsString( "state = 'applied', application_id = 44", $this->wpdb->lastQuery() );
	}

	public function test_once_marks_are_conditional_per_column_and_report_the_winner(): void {
		$repository = new MasterclassAccountRepository( $this->wpdb );
		$cases      = array(
			'first_login_at' => fn() => $repository->markFirstLogin( 15, '2026-11-10 06:05:00' ),
			'home_at'        => fn() => $repository->markHome( 15, '2026-11-10 14:00:00' ),
			'contact_at'     => fn() => $repository->markContact( 15, '2026-11-10 14:05:00' ),
		);

		foreach ( $cases as $column => $mark ) {
			$this->wpdb->queueQuery( 1 );
			self::assertTrue( $mark(), "$column: первая отметка" );
			self::assertStringContainsString( "`$column` = ", $this->wpdb->lastQuery() );
			self::assertStringContainsString( "AND `$column` IS NULL AND deleted_at IS NULL", $this->wpdb->lastQuery() );

			$this->wpdb->queueQuery( 0 );
			self::assertFalse( $mark(), "$column: вторая отметка не проходит" );
		}
	}

	public function test_mark_deleted_is_conditional_and_keeps_the_row(): void {
		$repository = new MasterclassAccountRepository( $this->wpdb );

		$this->wpdb->queueQuery( 1 );
		self::assertTrue( $repository->markDeleted( 15, AccountDeleteReason::Unused, '2026-11-17 12:00:00' ) );
		self::assertStringContainsString( "deleted_at = '2026-11-17 12:00:00', delete_reason = 'unused'", $this->wpdb->lastQuery() );
		self::assertStringContainsString( 'AND deleted_at IS NULL', $this->wpdb->lastQuery() );
		self::assertStringNotContainsString( 'DELETE FROM', $this->wpdb->lastQuery(), 'строка не удаляется — номер не должен повториться' );

		$this->wpdb->queueQuery( 0 );
		self::assertFalse( $repository->markDeleted( 15, AccountDeleteReason::Unused, '2026-11-17 12:01:00' ) );
	}

	public function test_mark_deleted_by_user_returns_the_account_number(): void {
		$repository = new MasterclassAccountRepository( $this->wpdb );

		$this->wpdb->queueVar( '15' );
		$this->wpdb->queueQuery( 1 );
		self::assertSame( 15, $repository->markDeletedByUserId( 501, AccountDeleteReason::UserDeleted, '2026-11-20 10:00:00' ) );
		self::assertStringContainsString( "delete_reason = 'user_deleted'", $this->wpdb->lastQuery() );
	}

	public function test_mark_deleted_by_user_without_a_live_account_does_nothing(): void {
		$repository = new MasterclassAccountRepository( $this->wpdb );

		$this->wpdb->queueVar( '0' );
		self::assertNull( $repository->markDeletedByUserId( 777, AccountDeleteReason::UserDeleted, '2026-11-20 10:00:00' ) );
		self::assertCount( 1, $this->wpdb->queries, 'UPDATE не выполнялся' );
	}

	public function test_mark_deleted_by_user_loses_the_race_to_another_deleter(): void {
		$repository = new MasterclassAccountRepository( $this->wpdb );

		$this->wpdb->queueVar( '15' );
		$this->wpdb->queueQuery( 0 );

		self::assertNull( $repository->markDeletedByUserId( 501, AccountDeleteReason::UserDeleted, '2026-11-20 10:00:00' ) );
	}

	public function test_clear_student_record(): void {
		( new MasterclassAccountRepository( $this->wpdb ) )->clearStudentRecord( 15 );

		self::assertStringContainsString( 'student_record_id = NULL', $this->wpdb->lastQuery() );
	}

	public function test_next_unused_takes_active_accounts_that_never_logged_in(): void {
		( new MasterclassAccountRepository( $this->wpdb ) )->nextUnused( 7, 100 );

		self::assertStringContainsString(
			"deleted_at IS NULL AND first_login_at IS NULL AND state = 'active' ORDER BY id ASC LIMIT 100",
			$this->wpdb->lastQuery()
		);
	}

	public function test_next_purgeable_takes_attached_live_accounts_after_the_cursor_and_skips_enrolled(): void {
		( new MasterclassAccountRepository( $this->wpdb ) )->nextPurgeable( 7, 100, 12 );

		self::assertStringContainsString(
			"masterclass_id = 7 AND id > 12 AND deleted_at IS NULL AND student_record_id IS NOT NULL AND state <> 'enrolled' ORDER BY id ASC LIMIT 100",
			$this->wpdb->lastQuery()
		);
	}

	public function test_count_live_skips_enrolled(): void {
		$this->wpdb->queueVar( '4' );

		self::assertSame( 4, ( new MasterclassAccountRepository( $this->wpdb ) )->countLive( 7 ) );
		self::assertStringContainsString( "deleted_at IS NULL AND state <> 'enrolled'", $this->wpdb->lastQuery() );
	}

	public function test_count_attached_counts_only_accounts_that_still_hold_a_record(): void {
		$this->wpdb->queueVar( '2' );

		self::assertSame( 2, ( new MasterclassAccountRepository( $this->wpdb ) )->countAttached( 7 ) );
		self::assertStringContainsString(
			"deleted_at IS NULL AND student_record_id IS NOT NULL AND state <> 'enrolled'",
			$this->wpdb->lastQuery()
		);
	}

	public function test_count_by_masterclass_counts_deleted_numbers_too(): void {
		$this->wpdb->queueVar( '60' );

		self::assertSame( 60, ( new MasterclassAccountRepository( $this->wpdb ) )->countByMasterclass( 7 ) );
		self::assertStringNotContainsString( 'deleted_at', $this->wpdb->lastQuery() );
	}

	public function test_stale_pending_are_numbers_without_a_user(): void {
		( new MasterclassAccountRepository( $this->wpdb ) )->listStalePending( '2026-11-01 09:00:00', 20 );

		self::assertStringContainsString(
			"user_id IS NULL AND deleted_at IS NULL AND created_at < '2026-11-01 09:00:00'",
			$this->wpdb->lastQuery()
		);
	}

	public function test_funnel_maps_the_row_and_counts_deleted_but_not_failed_creations(): void {
		$this->wpdb->queueRow( array( 'created' => '60', 'logged_in' => '40', 'at_home' => '12', 'contacted' => '5', 'applied' => '3', 'enrolled' => '1' ) );

		$funnel = ( new MasterclassAccountRepository( $this->wpdb ) )->funnel( 7 );

		self::assertSame( array( 'created' => 60, 'loggedIn' => 40, 'atHome' => 12, 'contacted' => 5, 'applied' => 3, 'enrolled' => 1 ), $funnel->toArray() );
		$sql = $this->wpdb->lastQuery();
		self::assertStringNotContainsString( 'deleted_at IS NULL', $sql, 'удалённые аккаунты входят в воронку' );
		self::assertStringContainsString( "delete_reason <> 'create_failed'", $sql, 'несозданные учётки — не выданные аккаунты' );
		self::assertStringContainsString( "state IN ( 'applied', 'enrolled' )", $sql );
	}

	public function test_funnel_of_an_empty_masterclass_is_zeros(): void {
		self::assertSame(
			array( 'created' => 0, 'loggedIn' => 0, 'atHome' => 0, 'contacted' => 0, 'applied' => 0, 'enrolled' => 0 ),
			( new MasterclassAccountRepository( $this->wpdb ) )->funnel( 7 )->toArray()
		);
	}

	// ── MasterclassLogRepository ──────────────────────────────────────────────────

	private function logInput(): MasterclassLogInputDTO {
		return new MasterclassLogInputDTO(
			action:        MasterclassLogAction::AccountsCreated,
			result:        'success',
			actorType:     'user',
			actorUserId:   1,
			actorLabel:    'Админ',
			masterclassId: 7,
			accountId:     null,
			subjectLabel:  null,
			actorIp:       '10.0.0.1',
			context:       array( 'created' => 25 ),
			createdAt:     '2026-11-01 10:00:00'
		);
	}

	public function test_log_create_writes_to_the_module_log_table(): void {
		$this->wpdb->insert_id = 9;

		$id = ( new MasterclassLogRepository( $this->wpdb ) )->create( $this->logInput() );

		self::assertSame( 9, $id );
		self::assertSame( 'wp_fs_lms_masterclass_log', $this->wpdb->inserts[0]['table'] );
		self::assertSame( 'accounts_created', $this->wpdb->inserts[0]['data']['action'] );
		self::assertSame( 7, $this->wpdb->inserts[0]['data']['masterclass_id'] );
	}

	public function test_log_list_without_filters_pages_newest_first(): void {
		$this->wpdb->queueResults(
			array(
				array( 'id' => '9', 'action' => 'accounts_created', 'created_at' => '2026-11-01 10:00:00', 'context' => '{"created":25}' ),
			)
		);
		$this->wpdb->queueVar( '31' );

		$page = ( new MasterclassLogRepository( $this->wpdb ) )->list( array(), 2, 30 );

		self::assertStringContainsString( 'WHERE 1=1 ORDER BY id DESC LIMIT 30 OFFSET 30', $this->wpdb->queries[0] );
		self::assertSame( 31, $page['total'] );
		self::assertSame( 9, $page['rows'][0]->id );
		self::assertSame( array( 'created' => 25 ), $page['rows'][0]->context );
	}

	public function test_log_list_applies_every_filter_to_rows_and_count(): void {
		( new MasterclassLogRepository( $this->wpdb ) )->list(
			array(
				'action'         => 'frozen',
				'masterclass_id' => 7,
				'account_id'     => 15,
				'date_from'      => '2026-11-01',
				'date_to'        => '2026-11-30',
			),
			1,
			50
		);

		foreach ( $this->wpdb->queries as $sql ) {
			self::assertStringContainsString( "action = 'frozen'", $sql );
			self::assertStringContainsString( 'masterclass_id = 7', $sql );
			self::assertStringContainsString( 'account_id = 15', $sql );
			self::assertStringContainsString( "created_at >= '2026-11-01 00:00:00'", $sql );
			self::assertStringContainsString( "created_at <= '2026-11-30 23:59:59'", $sql );
		}
		self::assertCount( 2, $this->wpdb->queries );
	}

	public function test_log_purge_older_than(): void {
		$this->wpdb->queueQuery( 12 );

		$deleted = ( new MasterclassLogRepository( $this->wpdb ) )->purgeOlderThan( '2025-11-01 00:00:00' );

		self::assertSame( 12, $deleted );
		self::assertStringContainsString( "DELETE FROM `wp_fs_lms_masterclass_log` WHERE created_at < '2025-11-01 00:00:00'", $this->wpdb->lastQuery() );
	}

	public function test_log_distinct_actions(): void {
		$this->wpdb->queueResults( array( array( 'action' => 'frozen' ), array( 'action' => 'login_at_home' ) ) );

		self::assertSame( array( 'frozen', 'login_at_home' ), ( new MasterclassLogRepository( $this->wpdb ) )->distinctActions() );
	}
}
