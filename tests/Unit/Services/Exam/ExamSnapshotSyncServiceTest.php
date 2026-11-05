<?php

declare( strict_types=1 );

namespace Tests\Unit\Services\Exam {
	use Inc\Managers\Wp\PostManager;
	use Inc\Repositories\WPDBRepositories\ExamLockRepository;
	use Inc\Repositories\WPDBRepositories\ExamSessionRepository;
	use Inc\Services\Exam\ExamEventService;
	use Inc\Services\Exam\ExamSnapshotSyncService;
	use Inc\Services\Exam\ExamVariantGuard;
	use Inc\Services\Exam\ExamVariantMutex;
	use PHPUnit\Framework\TestCase;

	class ExamSnapshotSyncServiceTest extends TestCase {
		private function service( ExamVariantGuard $guard, ExamSessionRepository $sessions, ExamEventService $events, ?PostManager $posts = null ): ExamSnapshotSyncService {
			$mutex = $this->createMock( ExamVariantMutex::class );
			$mutex->method( 'run' )->willReturnCallback( static fn( callable $op ): mixed => $op() );
			$locks = $this->createMock( ExamLockRepository::class );
			if ( null === $posts ) {
				$posts = $this->createMock( PostManager::class );
				$posts->method( 'get' )->willReturn( new \WP_Post( array( 'ID' => 500, 'post_type' => 'inf_ege_assessments' ) ) );
				$posts->method( 'updateMetaChecked' )->willReturnCallback( static function ( int $id, string $key, mixed $value, mixed $previous ): bool {
					$GLOBALS['_snapshot_test_write'] = array( $id, $key, $value, $previous );
					return true;
				} );
				$posts->method( 'clearCache' )->willReturnCallback( static function ( int $id ): void { $GLOBALS['_snapshot_test_cache_deleted'] = array( $id, 'post_meta' ); } );
			}
			$service = $this->getMockBuilder( ExamSnapshotSyncService::class )
				->setConstructorArgs( array( $mutex, $guard, $sessions, $events, $locks, $posts ) )
				->onlyMethods( array( 'inTransaction' ) )->getMock();
			$service->method( 'inTransaction' )->willReturnCallback( static fn( callable $op ): mixed => $op() );
			return $service;
		}

		public function test_updates_every_published_event_after_saving_meta(): void {
			$GLOBALS['_fs_test_post_types'][500] = 'inf_ege_assessments';
			$guard = $this->createMock( ExamVariantGuard::class );
			$guard->method( 'isAssessmentFrozen' )->willReturn( false );
			$sessions = $this->createMock( ExamSessionRepository::class );
			$sessions->method( 'publishedEventIdsForAssessment' )->with( 500 )->willReturn( array( 3, 8 ) );
			$events = $this->createMock( ExamEventService::class );
			$called = array();
			$events->expects( self::exactly( 2 ) )->method( 'rebuildSnapshotInTransaction' )->willReturnCallback( static function ( int $eventId, int $assessmentId ) use ( &$called ): void { $called[] = array( $eventId, $assessmentId ); } );
			$result = $this->service( $guard, $sessions, $events )->filterUpdate( null, 500, 'fs_lms_meta', array( 'task_ids' => array( 1 ) ) );
			self::assertTrue( $result );
			self::assertSame( array( array( 3, 500 ), array( 8, 500 ) ), $called );
			self::assertSame( array( 'task_ids' => array( 1 ) ), $GLOBALS['_snapshot_test_write'][2] );
		}

		public function test_started_variant_is_refused_before_metadata_write(): void {
			$GLOBALS['_fs_test_post_types'][500] = 'inf_ege_assessments';
			unset( $GLOBALS['_snapshot_test_write'] );
			$guard = $this->createMock( ExamVariantGuard::class );
			$guard->method( 'isAssessmentFrozen' )->willReturn( true );
			$events = $this->createMock( ExamEventService::class );
			$events->expects( self::never() )->method( 'rebuildSnapshotInTransaction' );
			try {
				$this->service( $guard, $this->createMock( ExamSessionRepository::class ), $events )->filterUpdate( null, 500, 'fs_lms_meta', array() );
				self::fail( 'A frozen variant must be refused.' );
			} catch ( \DomainException $error ) {
				self::assertStringContainsString( 'Экзамен уже начался', $error->getMessage() );
			}
			self::assertArrayNotHasKey( '_snapshot_test_write', $GLOBALS );
		}

		public function test_rebuild_failure_propagates_and_invalidates_wordpress_cache(): void {
			$GLOBALS['_fs_test_post_types'][500] = 'inf_ege_assessments';
			unset( $GLOBALS['_snapshot_test_cache_deleted'] );
			$guard = $this->createMock( ExamVariantGuard::class );
			$guard->method( 'isAssessmentFrozen' )->willReturn( false );
			$sessions = $this->createMock( ExamSessionRepository::class );
			$sessions->method( 'publishedEventIdsForAssessment' )->willReturn( array( 3, 8 ) );
			$events = $this->createMock( ExamEventService::class );
			$events->method( 'rebuildSnapshotInTransaction' )->willReturnCallback( static function ( int $id ): void {
				if ( 8 === $id ) { throw new \RuntimeException( 'snapshot failure' ); }
			} );
			try {
				$this->service( $guard, $sessions, $events )->filterUpdate( null, 500, 'fs_lms_meta', array() );
				self::fail( 'Rebuild failure must not be reported as a successful save.' );
			} catch ( \RuntimeException $error ) {
				self::assertSame( 'snapshot failure', $error->getMessage() );
				self::assertSame( array( 500, 'post_meta' ), $GLOBALS['_snapshot_test_cache_deleted'] );
			}
		}

		public function test_session_start_at_commit_boundary_aborts_save(): void {
			$GLOBALS['_fs_test_post_types'][500] = 'inf_ege_assessments';
			$guard = $this->createMock( ExamVariantGuard::class );
			$guard->method( 'isAssessmentFrozen' )->willReturnOnConsecutiveCalls( false, true );
			$sessions = $this->createMock( ExamSessionRepository::class );
			$sessions->method( 'publishedEventIdsForAssessment' )->willReturn( array( 3 ) );
			$this->expectExceptionMessage( 'Экзамен уже начался' );
			$this->service( $guard, $sessions, $this->createMock( ExamEventService::class ) )->filterUpdate( null, 500, 'fs_lms_meta', array() );
		}

		public function test_metadata_by_id_cannot_bypass_frozen_task_guard(): void {
			$posts = $this->createMock( PostManager::class );
			$posts->method( 'metaById' )->willReturn( (object) array( 'post_id' => 11, 'meta_key' => 'fs_lms_meta' ) );
			$posts->expects( self::never() )->method( 'updateMetaById' );
			$posts->expects( self::never() )->method( 'deleteMetaById' );
			$guard = $this->createMock( ExamVariantGuard::class );
			$guard->method( 'isPostFrozen' )->willReturn( true );
			$guard->method( 'isMetaUnchanged' )->willReturn( false );
			$guard->method( 'isConditionOnlyEdit' )->willReturn( false );
			$service = $this->service( $guard, $this->createMock( ExamSessionRepository::class ), $this->createMock( ExamEventService::class ), $posts );
			self::assertFalse( $service->filterUpdateById( null, 20, array( 'task_answer' => 'changed' ) ) );
			self::assertFalse( $service->filterDeleteById( null, 20 ) );
		}

		public function test_metadata_by_id_allows_condition_only_edit_of_frozen_task(): void {
			$posts = $this->createMock( PostManager::class );
			$posts->method( 'metaById' )->willReturn( (object) array( 'post_id' => 11, 'meta_key' => 'fs_lms_meta' ) );
			$posts->method( 'get' )->willReturn( new \WP_Post( array( 'ID' => 11, 'post_type' => 'inf_ege_tasks' ) ) );
			$guard = $this->createMock( ExamVariantGuard::class );
			$guard->method( 'isPostFrozen' )->willReturn( true );
			$guard->method( 'isConditionOnlyEdit' )->willReturn( true );
			$service = $this->service( $guard, $this->createMock( ExamSessionRepository::class ), $this->createMock( ExamEventService::class ), $posts );
			self::assertNull( $service->filterUpdateById( null, 20, array( 'task_condition' => 'новое' ) ) );
		}
	}
}
