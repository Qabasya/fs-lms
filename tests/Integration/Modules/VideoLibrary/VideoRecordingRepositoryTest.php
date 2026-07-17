<?php

declare( strict_types=1 );

namespace Integration\Modules\VideoLibrary;

use FakeWpdb;
use Inc\Modules\VideoLibrary\DTO\VideoRecordingInputDTO;
use Inc\Modules\VideoLibrary\Repositories\VideoRecordingRepository;
use PHPUnit\Framework\TestCase;

class VideoRecordingRepositoryTest extends TestCase {

	private FakeWpdb $wpdb;
	private VideoRecordingRepository $repo;

	protected function setUp(): void {
		parent::setUp();
		$this->wpdb = new FakeWpdb();
		$this->repo = new VideoRecordingRepository( $this->wpdb );
	}

	private function input( array $over = array() ): VideoRecordingInputDTO {
		return new VideoRecordingInputDTO(
			s3Bucket:        $over['bucket'] ?? 'test-bucket',
			s3Key:           $over['key'] ?? 'videos/kege-1/2026/07/rec.webm',
			manifestKey:     'videos/kege-1/2026/07/rec.webm.json',
			groupSlug:       'kege-1',
			groupId:         $over['group_id'] ?? 3,
			courseId:        42,
			teacherId:       7,
			teacherUsername: null,
			recordedAt:      '2026-07-08T16:04:45+03:00',
			sizeBytes:       123456789,
			sha256:          str_repeat( 'a', 64 ),
			durationSec:     null,
			payload:         '{"s3_key":"videos/kege-1/2026/07/rec.webm"}',
		);
	}

	/** @return object Строка таблицы как из wpdb (OBJECT). */
	private function row( array $over = array() ): object {
		return (object) array_merge( array(
			'id'              => 10,
			's3_bucket'       => 'test-bucket',
			's3_key'          => 'videos/kege-1/2026/07/rec.webm',
			'manifest_key'    => null,
			'group_slug'      => 'kege-1',
			'group_id'        => 3,
			'teacher_user_id' => null,
			'group_lesson_id' => null,
			'status'          => 'unmatched',
			'recorded_at'     => '2026-07-08 16:04:45',
			'size_bytes'      => 123456789,
			'sha256'          => str_repeat( 'a', 64 ),
			'duration_sec'    => null,
			'payload'         => '{}',
			'created_at'      => '2026-07-08 17:00:00',
			'updated_at'      => '2026-07-08 17:00:00',
		), $over );
	}

	public function test_find_by_s3_key_builds_query_and_maps_dto(): void {
		$this->wpdb->queueRow( $this->row( array( 'group_lesson_id' => 55, 'status' => 'matched' ) ) );

		$dto = $this->repo->findByS3Key( 'videos/kege-1/2026/07/rec.webm' );

		self::assertStringContainsString( "s3_key = 'videos/kege-1/2026/07/rec.webm'", $this->wpdb->lastQuery() );
		self::assertNotNull( $dto );
		self::assertSame( 10, $dto->id );
		self::assertSame( 55, $dto->groupLessonId );
		self::assertSame( 'matched', $dto->status );
		self::assertSame( 123456789, $dto->sizeBytes );
	}

	public function test_upsert_inserts_new_row_as_unmatched(): void {
		$this->wpdb->queueRow( null ); // findByS3Key → нет строки

		$result = $this->repo->upsertByS3Key( $this->input(), '2026-07-08 16:04:45', null );

		self::assertTrue( $result['isNew'] );
		self::assertNull( $result['existing'] );
		self::assertCount( 1, $this->wpdb->inserts );

		$data = $this->wpdb->inserts[0]['data'];
		self::assertSame( 'videos/kege-1/2026/07/rec.webm', $data['s3_key'] );
		self::assertSame( 'unmatched', $data['status'] );
		self::assertSame( '2026-07-08 16:04:45', $data['recorded_at'] );
		self::assertSame( 3, $data['group_id'] );
	}

	public function test_upsert_updates_existing_metadata_without_touching_binding(): void {
		$this->wpdb->queueRow( $this->row( array( 'group_lesson_id' => 55, 'status' => 'matched' ) ) );

		$result = $this->repo->upsertByS3Key( $this->input(), '2026-07-08 16:04:45', null );

		self::assertFalse( $result['isNew'] );
		self::assertSame( 10, $result['id'] );
		self::assertSame( 55, $result['existing']->groupLessonId );
		self::assertCount( 0, $this->wpdb->inserts );
		self::assertCount( 1, $this->wpdb->updates );

		$data = $this->wpdb->updates[0]['data'];
		self::assertArrayNotHasKey( 'group_lesson_id', $data );
		self::assertArrayNotHasKey( 'status', $data );
		self::assertArrayNotHasKey( 's3_key', $data );
		self::assertSame( array( 'id' => 10 ), $this->wpdb->updates[0]['where'] );
	}

	public function test_attach_sets_lesson_and_matched_status(): void {
		$this->repo->attach( 10, 55 );

		self::assertSame(
			array( 'group_lesson_id' => 55, 'status' => 'matched' ),
			$this->wpdb->updates[0]['data']
		);
		self::assertSame( array( 'id' => 10 ), $this->wpdb->updates[0]['where'] );
	}

	public function test_detach_resets_lesson_and_status(): void {
		$this->repo->detach( 10 );

		self::assertSame(
			array( 'group_lesson_id' => null, 'status' => 'unmatched' ),
			$this->wpdb->updates[0]['data']
		);
	}

	public function test_list_unmatched_filters_by_status_with_limit(): void {
		$this->wpdb->queueResults( array( $this->row() ) );

		$list = $this->repo->listUnmatched( 25 );

		$q = $this->wpdb->lastQuery();
		self::assertStringContainsString( "status = 'unmatched'", $q );
		self::assertStringContainsString( 'LIMIT 25', $q );
		self::assertCount( 1, $list );
		self::assertSame( 'unmatched', $list[0]->status );
	}

	public function test_list_by_group_lesson_builds_query(): void {
		$this->wpdb->queueResults( array() );

		$this->repo->listByGroupLesson( 55 );

		self::assertStringContainsString( 'group_lesson_id = 55', $this->wpdb->lastQuery() );
	}

	public function test_count_by_status_maps_rows(): void {
		$this->wpdb->queueResults( array(
			(object) array( 'status' => 'matched', 'cnt' => 4 ),
			(object) array( 'status' => 'unmatched', 'cnt' => 2 ),
		) );

		self::assertSame( array( 'matched' => 4, 'unmatched' => 2 ), $this->repo->countByStatus() );
	}
}
