<?php
declare( strict_types=1 );
namespace Unit\Callbacks\Deletion;
use Inc\Callbacks\Deletion\DeletionCallbacks;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Deletion\DeletionEventDispatcher;
use Inc\Services\Subject\SubjectArchiveGuard;
use PHPUnit\Framework\TestCase;
class DeletionCallbacksTest extends TestCase {
	public function test_subject_and_period_previews_refuse_service_group_before_confirming_deletion(): void {
		fs_test_reset_ajax(); $_POST = array( 'subject_key' => 'inf', 'period_id' => '2026' );
		$inventory = array( (object) array( 'id' => 1 ), (object) array( 'id' => 27, 'self_paced_course_id' => 15 ) );
		$groups = $this->createMock( GroupsRepository::class );
		$groups->method( 'findBySubjectKey' )->willReturn( $inventory );
		$groups->method( 'findByPeriodId' )->willReturn( $inventory );
		$records = $this->createMock( StudentRecordRepository::class );
		$records->expects( self::never() )->method( 'countUniqueStudentsByGroup' );
		$dispatcher = $this->createMock( DeletionEventDispatcher::class );
		$dispatcher->expects( self::never() )->method( 'dispatch' );
		$callback = new DeletionCallbacks( $dispatcher, $groups, $records, $this->createMock( SubjectArchiveGuard::class ) );
		foreach ( array( 'ajaxCheckSubjectDeletion', 'ajaxCheckPeriodDeletion' ) as $method ) {
			$response = fs_test_capture_json( fn() => $callback->$method() );
			self::assertFalse( $response->success );
			self::assertStringContainsString( 'История прохождения', $response->payload );
		}
	}
	public function test_direct_period_delete_returns_failure_when_cascade_preflight_refuses(): void {
		fs_test_reset_ajax(); $_POST = array( 'period_id' => '2026' );
		$dispatcher = $this->createMock( DeletionEventDispatcher::class );
		$dispatcher->method( 'dispatch' )->willThrowException( new \DomainException( 'История прохождения должна быть сохранена.' ) );
		$callback = new DeletionCallbacks( $dispatcher, $this->createMock( GroupsRepository::class ), $this->createMock( StudentRecordRepository::class ), $this->createMock( SubjectArchiveGuard::class ) );
		self::assertFalse( fs_test_capture_json( fn() => $callback->ajaxDeletePeriod() )->success );
	}
}
