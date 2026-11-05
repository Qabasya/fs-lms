<?php
declare( strict_types=1 );
namespace Unit\Callbacks\Enrollment;
use Inc\Callbacks\Enrollment\EnrollmentLifecycleCallbacks;
use Inc\Contracts\LogEventDispatcherInterface;
use Inc\Repositories\WPDBRepositories\ApplicationRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Enrollment\EnrollmentService;
use PHPUnit\Framework\TestCase;
class EnrollmentLifecycleCallbacksTest extends TestCase {
	public function test_primary_picker_excludes_service_but_keeps_shared_scheduled_and_legacy_open(): void {
		fs_test_reset_ajax();
		$_POST = array( 'period_id' => '2026', 'subject_id' => 'inf' );
		$groups = $this->createMock( GroupsRepository::class );
		$groups->method( 'findByPeriodAndSubject' )->willReturn( array(
			(object) array( 'id' => 1, 'name' => 'Primary', 'access_mode' => 'scheduled' ),
			(object) array( 'id' => 2, 'name' => 'Shared', 'access_mode' => 'scheduled', 'is_shared' => 1 ),
			(object) array( 'id' => 3, 'name' => 'Open legacy', 'access_mode' => 'open' ),
			(object) array( 'id' => 4, 'name' => 'Service', 'access_mode' => 'open', 'self_paced_course_id' => 15 ),
		) );
		$records = $this->createMock( StudentRecordRepository::class );
		$records->expects( self::never() )->method( 'countActiveByGroup' );
		$callback = new EnrollmentLifecycleCallbacks( $this->createMock( ApplicationRepository::class ), $this->createMock( EnrollmentService::class ), $this->createMock( LogEventDispatcherInterface::class ), $groups, $records );
		$response = fs_test_capture_json( fn() => $callback->ajaxGetStudentGroups() );
		self::assertTrue( $response->success );
		self::assertSame( array( 1, 2, 3 ), array_column( $response->payload, 'id' ) );
	}
}
