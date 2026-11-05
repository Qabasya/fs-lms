<?php

declare( strict_types=1 );

namespace Unit\Modules\VideoLibrary;

use Inc\Modules\VideoLibrary\DTO\VideoRecordingDTO;
use Inc\Modules\VideoLibrary\DTO\VideoRecordingInputDTO;
use Inc\Modules\VideoLibrary\Repositories\VideoRecordingRepository;
use PHPUnit\Framework\TestCase;

class VideoRecordingRepositoryTest extends TestCase {
	private function input(): VideoRecordingInputDTO {
		return new VideoRecordingInputDTO( 'new', 'videos/a', null, 'group', null, null, null, null, '2026-11-04T12:00:00+02:00', 12, str_repeat( 'a', 64 ), null, '{}' );
	}

	public function test_insert_failure_does_not_report_success(): void {
		$db = $this->createMock( \wpdb::class );
		$db->method( 'prepare' )->willReturn( 'SELECT video' );
		$db->method( 'get_row' )->willReturn( null );
		$db->expects( self::once() )->method( 'insert' )->willReturn( false );
		$this->expectException( \RuntimeException::class );
		( new VideoRecordingRepository( $db ) )->upsertByS3Key( $this->input(), '2026-11-04 12:00:00', null );
	}

	public function test_update_failure_does_not_report_success(): void {
		$db = $this->createMock( \wpdb::class );
		$repo = $this->getMockBuilder( VideoRecordingRepository::class )->setConstructorArgs( array( $db ) )->onlyMethods( array( 'findByS3Key' ) )->getMock();
		$existing = new VideoRecordingDTO( 7, 'old', 'videos/a', null, 'group', null, null, 9, 'matched', '2026-11-04 12:00:00', 12, '', null, null, '', '' );
		$repo->method( 'findByS3Key' )->willReturn( $existing );
		$db->expects( self::once() )->method( 'update' )->willReturn( false );
		$this->expectException( \RuntimeException::class );
		$repo->upsertByS3Key( $this->input(), '2026-11-04 12:00:00', null );
	}

	public function test_unchanged_update_preserves_existing_binding(): void {
		$db = $this->createMock( \wpdb::class );
		$repo = $this->getMockBuilder( VideoRecordingRepository::class )->setConstructorArgs( array( $db ) )->onlyMethods( array( 'findByS3Key' ) )->getMock();
		$existing = new VideoRecordingDTO( 7, 'new', 'videos/a', null, 'group', null, null, 9, 'matched', '2026-11-04 12:00:00', 12, '', null, null, '', '' );
		$repo->method( 'findByS3Key' )->willReturn( $existing );
		$db->expects( self::once() )->method( 'update' )->willReturn( 0 );
		$result = $repo->upsertByS3Key( $this->input(), '2026-11-04 12:00:00', null );
		self::assertSame( 7, $result['id'] );
		self::assertSame( $existing, $result['existing'] );
		self::assertFalse( $result['isNew'] );
	}
}
