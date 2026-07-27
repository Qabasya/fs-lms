<?php

declare(strict_types=1);

namespace Integration\Repositories;

use FakeWpdb;
use Inc\Repositories\WPDBRepositories\NotificationRepository;
use PHPUnit\Framework\TestCase;

/**
 * Интеграционный тест NotificationRepository.
 *
 * Проверяет форму SQL (INSERT IGNORE, NULL для необязательных колонок,
 * скоуп по recipient_user_id) и интерпретацию affected rows как bool
 * (вставлено / дубль по dedupe-ключу пропущен).
 */
class NotificationRepositoryIntegrationTest extends TestCase {

	private FakeWpdb $wpdb;
	private NotificationRepository $repo;

	protected function setUp(): void {
		parent::setUp();
		$this->wpdb = new FakeWpdb();
		$this->repo = new NotificationRepository( $this->wpdb );
	}

	public function test_insert_ignore_returns_true_when_row_inserted(): void {
		$this->wpdb->queueQuery( 1 );

		$result = $this->repo->insertIgnore(
			7,
			'work_graded',
			'graded:42',
			array( 'topic' => 'Тема' ),
			'/profile/',
			5,
			'submission',
			42
		);

		self::assertTrue( $result );
		$query = $this->wpdb->lastQuery();
		self::assertStringContainsString( 'INSERT IGNORE', $query );
		self::assertStringContainsString( 'recipient_user_id', $query );
		self::assertStringContainsString( "'graded:42'", $query );
		self::assertStringContainsString( "'work_graded'", $query );
	}

	public function test_insert_ignore_returns_false_when_duplicate_ignored(): void {
		$this->wpdb->queueQuery( 0 );

		$result = $this->repo->insertIgnore( 7, 'work_graded', 'graded:42' );

		self::assertFalse( $result );
	}

	public function test_insert_ignore_writes_null_literal_for_omitted_optional_columns(): void {
		$this->wpdb->queueQuery( 1 );

		$this->repo->insertIgnore( 7, 'attendance_missed', 'att:1:2' );

		$query = $this->wpdb->lastQuery();
		self::assertMatchesRegularExpression( '/VALUES \(7, \'attendance_missed\', NULL, NULL, NULL,/', $query );
	}

	public function test_list_recent_orders_desc_with_limit(): void {
		$this->wpdb->queueResults( array(
			array(
				'id'                => 1,
				'recipient_user_id' => 7,
				'type'              => 'work_graded',
				'group_id'          => null,
				'entity_type'       => null,
				'entity_id'         => null,
				'payload'           => '{"topic":"Тема"}',
				'url'               => '/profile/',
				'dedupe_key'        => 'graded:1',
				'created_at'        => '2026-07-27 10:00:00',
				'seen_at'           => null,
				'read_at'           => null,
			),
		) );

		$items = $this->repo->listRecent( 7, 30 );

		self::assertCount( 1, $items );
		self::assertSame( 'work_graded', $items[0]->type->value );
		self::assertSame( array( 'topic' => 'Тема' ), $items[0]->payload );
		$query = $this->wpdb->lastQuery();
		self::assertStringContainsString( 'ORDER BY created_at DESC', $query );
		self::assertStringContainsString( 'LIMIT 30', $query );
		self::assertStringContainsString( 'recipient_user_id = 7', $query );
	}

	public function test_unseen_count_filters_by_seen_at_null(): void {
		$this->wpdb->queueVar( 3 );

		self::assertSame( 3, $this->repo->unseenCount( 7 ) );
		self::assertStringContainsString( 'seen_at IS NULL', $this->wpdb->lastQuery() );
	}

	public function test_mark_all_seen_scopes_by_recipient(): void {
		$this->repo->markAllSeen( 7 );

		$query = $this->wpdb->lastQuery();
		self::assertStringContainsString( 'seen_at', $query );
		self::assertStringContainsString( 'recipient_user_id = 7', $query );
	}

	public function test_mark_read_scopes_by_recipient_and_id(): void {
		$this->repo->markRead( 7, 42 );

		$query = $this->wpdb->lastQuery();
		self::assertStringContainsString( 'read_at', $query );
		self::assertStringContainsString( 'id = 42', $query );
		self::assertStringContainsString( 'recipient_user_id = 7', $query );
	}

	public function test_delete_by_dedupe_scopes_by_ids_and_key(): void {
		$this->repo->deleteByDedupe( array( 7, 8 ), 'att:1:2' );

		$query = $this->wpdb->lastQuery();
		self::assertStringContainsString( 'IN (7, 8)', $query );
		self::assertStringContainsString( "'att:1:2'", $query );
	}

	public function test_delete_by_dedupe_noop_for_empty_ids(): void {
		$this->repo->deleteByDedupe( array(), 'att:1:2' );

		self::assertSame( array(), $this->wpdb->queries );
	}

	public function test_purge_uses_both_retention_windows(): void {
		$this->repo->purge( 30, 90 );

		$query = $this->wpdb->lastQuery();
		self::assertStringContainsString( 'INTERVAL 30 DAY', $query );
		self::assertStringContainsString( 'INTERVAL 90 DAY', $query );
		self::assertStringContainsString( 'read_at', $query );
		self::assertStringContainsString( 'created_at', $query );
	}
}
