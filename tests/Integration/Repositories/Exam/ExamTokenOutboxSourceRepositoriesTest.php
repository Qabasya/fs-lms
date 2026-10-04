<?php

declare( strict_types=1 );

namespace Integration\Repositories\Exam;

use FakeWpdb;
use Inc\Enums\Exam\ExamTokenPurpose;
use Inc\Repositories\WPDBRepositories\ExamAccessTokenRepository;
use Inc\Repositories\WPDBRepositories\ExamOperationKeyRepository;
use Inc\Repositories\WPDBRepositories\ExamOutboxEventRepository;
use Inc\Repositories\WPDBRepositories\ExamSourceRepository;
use PHPUnit\Framework\TestCase;

/** Ключи доступа, outbox, ключи идемпотентности и источники: условия выборок и захват событий воркером. */
class ExamTokenOutboxSourceRepositoriesTest extends TestCase {

	private FakeWpdb $wpdb;

	protected function setUp(): void {
		parent::setUp();
		$this->wpdb = new FakeWpdb();
	}

	/** @return array<string, mixed> */
	private function outboxRow( int $id ): array {
		return array(
			'id' => (string) $id, 'event_uuid' => 'uuid-' . $id, 'type' => 'event_published', 'aggregate_type' => 'event', 'aggregate_id' => '3',
			'aggregate_version' => '2', 'payload' => '{}', 'available_at' => '2026-03-10 07:00:00', 'attempts' => '1', 'created_at' => '2026-03-10 07:00:00',
		);
	}

	// ---- ключи доступа ----------------------------------------------------------------------------------------------------------

	public function test_active_token_is_the_not_revoked_one_of_the_target(): void {
		( new ExamAccessTokenRepository( $this->wpdb ) )->findActive( ExamTokenPurpose::Invitation, 14 );

		$sql = $this->wpdb->lastQuery();
		self::assertStringContainsString( "purpose = 'invitation' AND target_id = 14 AND revoked_at IS NULL", $sql );
		self::assertStringContainsString( 'ORDER BY id DESC LIMIT 1', $sql );
	}

	public function test_token_is_found_by_hash_not_by_plain_key(): void {
		( new ExamAccessTokenRepository( $this->wpdb ) )->findByHash( str_repeat( 'f', 64 ) );

		self::assertStringContainsString( "token_hash = '" . str_repeat( 'f', 64 ) . "'", $this->wpdb->lastQuery() );
	}

	public function test_revoke_by_target_touches_only_active_tokens_and_returns_their_number(): void {
		$this->wpdb->queueQuery( 2 );

		$count = ( new ExamAccessTokenRepository( $this->wpdb ) )->revokeByTarget( ExamTokenPurpose::Entry, 5, '2026-03-10 07:00:00' );

		self::assertSame( 2, $count );
		self::assertStringContainsString( "revoked_at = '2026-03-10 07:00:00'", $this->wpdb->lastQuery() );
		self::assertStringContainsString( 'revoked_at IS NULL', $this->wpdb->lastQuery() );
	}

	public function test_max_generation_is_zero_without_tokens(): void {
		$this->wpdb->queueVar( '0' );

		self::assertSame( 0, ( new ExamAccessTokenRepository( $this->wpdb ) )->maxGeneration( ExamTokenPurpose::Entry, 5 ) );
	}

	// ---- outbox -----------------------------------------------------------------------------------------------------------------

	public function test_lease_batch_claims_only_events_it_won(): void {
		$repo = new ExamOutboxEventRepository( $this->wpdb );
		$this->wpdb->queueCol( array( '1', '2', '3' ) );
		$this->wpdb->queueQuery( 1 ); // событие 1 захвачено
		$this->wpdb->queueRow( $this->outboxRow( 1 ) );
		$this->wpdb->queueQuery( 0 ); // событие 2 увёл другой воркер
		$this->wpdb->queueQuery( 1 ); // событие 3 захвачено
		$this->wpdb->queueRow( $this->outboxRow( 3 ) );

		$claimed = $repo->leaseBatch( '2026-03-10 07:00:00', '2026-03-10 07:05:00', 100 );

		self::assertSame( array( 1, 3 ), array_map( static fn ( $e ): int => $e->id, $claimed ) );
	}

	public function test_lease_selects_only_due_unprocessed_and_unleased_events_by_utc(): void {
		( new ExamOutboxEventRepository( $this->wpdb ) )->leaseBatch( '2026-03-10 07:00:00', '2026-03-10 07:05:00', 10 );

		$sql = $this->wpdb->queries[0];
		self::assertStringContainsString( 'processed_at IS NULL', $sql );
		self::assertStringContainsString( "available_at <= '2026-03-10 07:00:00'", $sql );
		self::assertStringContainsString( "leased_until < '2026-03-10 07:00:00'", $sql );
		self::assertStringNotContainsString( 'NOW()', $sql, 'Время — параметром UTC, а не поясом сервера базы.' );
		self::assertStringContainsString( 'LIMIT 10', $sql );
	}

	public function test_lease_update_is_conditional_so_two_workers_cannot_share_an_event(): void {
		$this->wpdb->queueCol( array( '1' ) );
		$this->wpdb->queueQuery( 0 );

		( new ExamOutboxEventRepository( $this->wpdb ) )->leaseBatch( '2026-03-10 07:00:00', '2026-03-10 07:05:00', 10 );

		$sql = $this->wpdb->queries[1];
		self::assertStringContainsString( 'attempts = attempts + 1', $sql );
		self::assertStringContainsString( 'processed_at IS NULL AND ( leased_until IS NULL OR leased_until <', $sql );
	}

	public function test_mark_processed_releases_the_lease(): void {
		( new ExamOutboxEventRepository( $this->wpdb ) )->markProcessed( 4, '2026-03-10 07:01:00' );

		self::assertStringContainsString( "processed_at = '2026-03-10 07:01:00', leased_until = NULL, last_error = NULL", $this->wpdb->lastQuery() );
	}

	public function test_mark_failed_keeps_error_short_and_reschedules(): void {
		( new ExamOutboxEventRepository( $this->wpdb ) )->markFailed( 4, str_repeat( 'x', 1500 ), '2026-03-10 07:10:00' );

		$sql = $this->wpdb->lastQuery();
		self::assertStringContainsString( "available_at = '2026-03-10 07:10:00'", $sql );
		self::assertStringContainsString( 'leased_until = NULL', $sql );
		self::assertStringContainsString( "last_error = '" . str_repeat( 'x', 1000 ) . "'", $sql );
		self::assertStringNotContainsString( str_repeat( 'x', 1001 ), $sql );
	}

	// ---- ключи идемпотентности ----------------------------------------------------------------------------------------------------

	public function test_purge_expired_deletes_by_given_utc_time(): void {
		$this->wpdb->queueQuery( 7 );

		$count = ( new ExamOperationKeyRepository( $this->wpdb ) )->purgeExpired( '2026-03-10 07:00:00' );

		self::assertSame( 7, $count );
		self::assertStringContainsString( "expires_at <= '2026-03-10 07:00:00'", $this->wpdb->lastQuery() );
	}

	public function test_operation_key_is_looked_up_by_scope_operation_and_key(): void {
		( new ExamOperationKeyRepository( $this->wpdb ) )->findByKey( 'participant:4', 'register', 'k1' );

		self::assertStringContainsString( "scope = 'participant:4' AND operation = 'register' AND request_key = 'k1'", $this->wpdb->lastQuery() );
	}

	// ---- источники --------------------------------------------------------------------------------------------------------------

	public function test_source_find_for_update_locks_the_row(): void {
		( new ExamSourceRepository( $this->wpdb ) )->findForUpdate( 14 );

		self::assertStringContainsString( 'WHERE id = 14 FOR UPDATE', $this->wpdb->lastQuery() );
	}

	public function test_bump_generation_increments_in_one_statement_without_version_change(): void {
		$this->wpdb->queueVar( '3' );

		$generation = ( new ExamSourceRepository( $this->wpdb ) )->bumpGeneration( 14 );

		self::assertSame( 3, $generation );
		self::assertStringContainsString( 'key_generation = key_generation + 1', $this->wpdb->queries[0] );
		self::assertStringNotContainsString( 'version', $this->wpdb->queries[0] );
	}

	public function test_source_key_revocation_can_be_cleared(): void {
		( new ExamSourceRepository( $this->wpdb ) )->setKeyRevokedAt( 14, null );

		self::assertStringContainsString( 'key_revoked_at = NULL', $this->wpdb->lastQuery() );
	}

	public function test_sources_of_event_include_inactive_ones_for_management(): void {
		( new ExamSourceRepository( $this->wpdb ) )->listByEvent( 3 );

		self::assertStringNotContainsString( 'is_active', $this->wpdb->lastQuery() );
		self::assertStringContainsString( 'event_id = 3 ORDER BY id ASC', $this->wpdb->lastQuery() );
	}
}
