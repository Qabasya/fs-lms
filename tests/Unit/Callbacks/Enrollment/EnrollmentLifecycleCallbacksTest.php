<?php

declare( strict_types=1 );

namespace Unit\Callbacks\Enrollment;

use Inc\Callbacks\Enrollment\EnrollmentLifecycleCallbacks;
use Inc\Contracts\LogEventDispatcherInterface;
use Inc\Repositories\WPDBRepositories\ApplicationRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Enrollment\EnrollmentService;
use Inc\Services\Group\ServiceGroupResolver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/** Выбор группы при зачислении заявки: служебные группы модулей не предлагаются. */
class EnrollmentLifecycleCallbacksTest extends TestCase {

	private GroupsRepository&MockObject $groups;
	private EnrollmentLifecycleCallbacks $cb;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_ajax();
		unset( $GLOBALS['_fs_test_filter_returns'] );

		$this->groups = $this->createMock( GroupsRepository::class );
		$this->cb     = new EnrollmentLifecycleCallbacks(
			$this->createStub( ApplicationRepository::class ),
			$this->createStub( EnrollmentService::class ),
			$this->createStub( LogEventDispatcherInterface::class ),
			$this->groups,
			$this->createStub( StudentRecordRepository::class ),
			new ServiceGroupResolver()
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_fs_test_filter_returns'] );
		parent::tearDown();
	}

	private function group( int $id, string $name ): object {
		return (object) array( 'id' => $id, 'name' => $name, 'access_mode' => 'scheduled' );
	}

	/** @return list<array{id: int, title: string}> */
	private function offered(): array {
		$_POST = array( 'period_id' => '2024-2025', 'subject_id' => 'inf' );

		return fs_test_capture_json( fn() => $this->cb->ajaxGetStudentGroups() )->payload;
	}

	public function test_all_groups_are_offered_without_service_groups(): void {
		$this->groups->method( 'findByPeriodAndSubject' )->willReturn( array( $this->group( 1, 'Группа А' ), $this->group( 2, 'Группа Б' ) ) );

		self::assertSame( array( 1, 2 ), array_column( $this->offered(), 'id' ) );
	}

	public function test_service_groups_are_not_offered_for_enrollment(): void {
		$GLOBALS['_fs_test_filter_returns']['fs_lms_service_group_ids'] = array( 2 );
		$this->groups->method( 'findByPeriodAndSubject' )->willReturn( array( $this->group( 1, 'Группа А' ), $this->group( 2, 'Служебная' ), $this->group( 3, 'Группа В' ) ) );

		$offered = $this->offered();

		self::assertSame( array( 1, 3 ), array_column( $offered, 'id' ), 'список остаётся плоским (ключи сброшены)' );
		self::assertSame( 'Группа В', $offered[1]['title'] );
	}
}
