<?php

declare( strict_types=1 );

namespace Unit\Managers\Course;

use Inc\Managers\Course\WorkManager;
use Inc\Managers\Wp\PostManager;
use Inc\Enums\Wp\PostMetaName;
use Inc\Repositories\OptionsRepositories\RankingAssignmentSnapshotRepository;
use Inc\Services\Course\RankingWorkSnapshotCoordinator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class WorkPointsTest extends TestCase {
	public function test_default_save_and_delete_do_not_invoke_snapshot_capture(): void {
		$posts = $this->posts();
		$posts->expects( self::once() )->method( 'updateMeta' );
		$posts->expects( self::once() )->method( 'delete' )->with( 5 );
		$snapshots = $this->createMock( RankingAssignmentSnapshotRepository::class );
		$snapshots->expects( self::never() )->method( 'synchronizedWrite' );
		$snapshots->expects( self::never() )->method( 'acquireLock' );
		$coordinator = $this->createMock( RankingWorkSnapshotCoordinator::class );
		$coordinator->expects( self::never() )->method( 'captureBeforeChange' );
		$manager = new WorkManager( $posts, null, $snapshots, $coordinator );
		self::assertTrue( $manager->setItemIds( 5, array( 20 ) ) );
		self::assertTrue( $manager->delete( 5 ) );
	}

	public function test_saves_items_after_capturing_previous_assigned_work(): void {
		$events = array();
		$posts = $this->posts();
		$posts->method( 'updateMeta' )->willReturnCallback( static function () use ( &$events ): void { $events[] = 'write'; } );
		$snapshots = $this->createMock( RankingAssignmentSnapshotRepository::class );
		$snapshots->method( 'synchronizedWrite' )->willReturnCallback( static function ( callable $capture, callable $write ): void { $capture(); $write(); } );
		$coordinator = $this->createMock( RankingWorkSnapshotCoordinator::class );
		$coordinator->expects( self::once() )->method( 'captureBeforeChange' )->willReturnCallback( static function ( $work ) use ( &$events ): void {
			self::assertSame( array( 10 => 4, 20 => 5 ), $work->taskPoints );
			$events[] = 'capture';
		} );
		self::assertTrue( ( new WorkManager( $posts, null, $snapshots, $coordinator, true ) )->setItemIds( 5, array( 20 ), array( 20 => 2 ) ) );
		self::assertSame( array( 'capture', 'write' ), $events );
	}

	public function test_snapshot_failure_prevents_the_work_change(): void {
		$posts = $this->posts();
		$posts->expects( self::never() )->method( 'updateMeta' );
		$snapshots = $this->createMock( RankingAssignmentSnapshotRepository::class );
		$snapshots->method( 'synchronizedWrite' )->willReturnCallback( static function ( callable $capture ): void { $capture(); } );
		$coordinator = $this->createMock( RankingWorkSnapshotCoordinator::class );
		$coordinator->method( 'captureBeforeChange' )->willThrowException( new \RuntimeException( 'snapshot failed' ) );
		$this->expectException( \RuntimeException::class );
		( new WorkManager( $posts, null, $snapshots, $coordinator, true ) )->setItemIds( 5, array( 20 ) );
	}

	public function test_snapshot_failure_prevents_deleting_the_work(): void {
		$posts = $this->posts();
		$posts->expects( self::never() )->method( 'delete' );
		$snapshots = $this->createMock( RankingAssignmentSnapshotRepository::class );
		$snapshots->method( 'synchronizedWrite' )->willReturnCallback( static function ( callable $capture ): void { $capture(); } );
		$coordinator = $this->createMock( RankingWorkSnapshotCoordinator::class );
		$coordinator->method( 'captureBeforeChange' )->willThrowException( new \RuntimeException( 'snapshot failed' ) );
		$this->expectException( \RuntimeException::class );
		( new WorkManager( $posts, null, $snapshots, $coordinator, true ) )->delete( 5 );
	}

	private function posts(): PostManager {
		$posts = $this->createMock( PostManager::class );
		$posts->method( 'get' )->willReturn( new \WP_Post( array( 'ID' => 5, 'post_type' => 'inf_works' ) ) );
		$posts->method( 'getMeta' )->willReturn( array( 'item_ids' => array( 10, 20 ), 'task_points' => array( 10 => 4, 20 => 5 ), 'task_number_prefix' => 'Т1', 'work_type' => 'homework' ) );
		return $posts;
	}

	public function test_item_save_preserves_unrelated_meta_and_excludes_removed_and_nonmember_weights(): void {
		$posts = $this->posts();
		$posts->expects( self::once() )->method( 'updateMeta' )->with( 5, PostMetaName::Meta->value, array( 'item_ids' => array( 20, 30 ), 'task_points' => array( 20 => 2, 30 => 1 ), 'task_number_prefix' => 'Т1', 'work_type' => 'homework' ) );
		self::assertTrue( ( new WorkManager( $posts ) )->setItemIds( 5, array( 20, 30 ), array( 20 => '2', 999 => 'bad' ) ) );
	}

	public function test_legacy_item_only_save_keeps_existing_weights_and_defaults_new_tasks(): void {
		$posts = $this->posts();
		$posts->expects( self::once() )->method( 'updateMeta' )->with( 5, PostMetaName::Meta->value, self::callback( static fn( array $meta ): bool => $meta['task_points'] === array( 20 => 5, 30 => 1 ) ) );
		self::assertTrue( ( new WorkManager( $posts ) )->setItemIds( 5, array( 20, 30 ) ) );
	}

	public static function invalidPoints(): array {
		return array_map( static fn( mixed $value ): array => array( $value ), array( null, 0, -1, 1000, '1.5', 2.5, true, array( 2 ), 'bad', '1e2' ) );
	}

	#[DataProvider( 'invalidPoints' )]
	public function test_invalid_member_points_reject_entire_save( mixed $value ): void {
		$posts = $this->posts();
		$posts->expects( self::never() )->method( 'updateMeta' );
		$this->expectException( \InvalidArgumentException::class );
		( new WorkManager( $posts ) )->setItemIds( 5, array( 10 ), array( 10 => $value ) );
	}

	public function test_total_above_storage_limit_rejects_entire_save(): void {
		$posts = $this->posts();
		$posts->expects( self::never() )->method( 'updateMeta' );
		$this->expectException( \InvalidArgumentException::class );
		( new WorkManager( $posts ) )->setItemIds( 5, range( 1, 11 ), array_fill_keys( range( 1, 11 ), 999 ) );
	}

}
