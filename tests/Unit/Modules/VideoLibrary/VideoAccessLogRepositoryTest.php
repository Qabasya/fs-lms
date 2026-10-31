<?php

declare(strict_types=1);

namespace Unit\Modules\VideoLibrary;

use Inc\Modules\VideoLibrary\Repositories\VideoAccessLogRepository;
use Inc\Modules\VideoLibrary\Schema\VideoSchema;
use PHPUnit\Framework\TestCase;

/**
 * Агрегация журнала доступа: повторные события того же пользователя по той же
 * записи в пределах окна докручивают открытую строку, а не плодят новые.
 */
class VideoAccessLogRepositoryTest extends TestCase {

	private \FakeWpdb $db;
	private VideoAccessLogRepository $repo;

	protected function setUp(): void {
		parent::setUp();
		$this->db   = new \FakeWpdb();
		$this->repo = new VideoAccessLogRepository( $this->db );
	}

	public function test_first_event_inserts_row(): void {
		$this->db->queueVar( null ); // открытой сессии нет
		$this->db->insert_id = 7;

		$id = $this->repo->touch( 10, 'fs_student', 42, 'bucket', 'videos/rec.webm', '1.2.3.4', requests: 1 );

		self::assertSame( 7, $id );
		self::assertCount( 1, $this->db->inserts );
		$data = $this->db->inserts[0]['data'];
		self::assertSame( 10, $data['wp_user_id'] );
		self::assertSame( 'videos/rec.webm', $data['s3_key'] );
		self::assertSame( 1, $data['requests_count'] );
		self::assertSame( 0, $data['watch_seconds'] );
		self::assertSame( '1.2.3.4', $data['ip'] );
		self::assertStringContainsString( VideoSchema::accessTable(), $this->db->queries[0] );
	}

	public function test_repeated_event_updates_open_session(): void {
		$this->db->queueVar( 5 ); // открытая строка найдена

		$id = $this->repo->touch( 10, 'fs_student', 42, 'bucket', 'videos/rec.webm', '1.2.3.4', watchSeconds: 55 );

		self::assertSame( 5, $id );
		self::assertCount( 0, $this->db->inserts );
		$sql = $this->db->lastQuery();
		self::assertStringContainsString( 'watch_seconds   = watch_seconds + 55', $sql );
		self::assertStringContainsString( 'group_lesson_id = 42', $sql );
	}

	public function test_update_without_lesson_id_does_not_zero_it(): void {
		$this->db->queueVar( 5 );

		$this->repo->touch( 10, 'fs_student', null, 'bucket', 'videos/rec.webm', '1.2.3.4', requests: 1 );

		self::assertStringNotContainsString( 'group_lesson_id =', $this->db->lastQuery() );
	}

	public function test_other_users_session_is_not_reused(): void {
		$this->db->queueVar( null ); // для пользователя 11 открытой строки нет
		$this->db->insert_id = 8;

		$this->repo->touch( 11, 'fs_parent', null, 'bucket', 'videos/rec.webm', '5.6.7.8', requests: 1 );

		self::assertStringContainsString( 'wp_user_id = 11', $this->db->queries[0] );
		self::assertCount( 1, $this->db->inserts );
	}

	public function test_top_by_watch_groups_by_user_and_key(): void {
		$this->db->queueResults( array(
			array( 'wp_user_id' => 10, 'user_role' => 'fs_student', 's3_key' => 'a.webm', 'requests' => 3, 'watch_seconds' => 3600, 'sessions' => 2, 'last_at' => '2026-10-31 10:00:00' ),
		) );

		$top = $this->repo->topByWatch( '2026-10-24 00:00:00' );

		self::assertCount( 1, $top );
		self::assertSame( 3600, $top[0]['watch_seconds'] );
		self::assertStringContainsString( 'GROUP BY wp_user_id, s3_key', $this->db->lastQuery() );
	}
}
