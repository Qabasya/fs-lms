<?php

declare( strict_types=1 );

namespace Unit\Callbacks\Group;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\Callbacks\Group\StudentGroupCallbacks;
use Inc\DTO\Enrollment\StudentRecordDTO;
use Inc\DTO\Person\PersonDTO;
use Inc\DTO\Person\UserDTO;
use Inc\Enums\Access\UserRole;
use Inc\Repositories\OptionsRepositories\UserRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Enrollment\OpenGroupEnrollmentService;
use Inc\Services\Group\GroupModeService;
use Inc\Services\Group\ServiceGroupResolver;
use PHPUnit\Framework\TestCase;

/**
 * Подбор ученика в открытую группу: временные аккаунты служебной группы в подборе — шум (добавлять их некуда), их не показываем.
 */
class StudentGroupSearchCallbacksTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_ajax();
		$GLOBALS['_fs_test_filter_returns']['fs_lms_service_group_ids'] = array( 31 );
		$_POST = array();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_fs_test_filter_returns'] );
		$_POST = array();
		parent::tearDown();
	}

	private function record( int $groupId, int $personId ): StudentRecordDTO {
		return StudentRecordDTO::fromArray( array(
			'id' => $personId, 'student_person_id' => $personId, 'parent_person_id' => 0, 'group_id' => $groupId, 'status' => 'active',
			'enrolled_at' => '2026-09-01 00:00:00', 'created_at' => '2026-09-01 00:00:00', 'updated_at' => '2026-09-01 00:00:00',
		) );
	}

	private function person( int $id, int $userId ): PersonDTO {
		return PersonDTO::fromArray( array(
			'id' => $id, 'wp_user_id' => $userId, 'last_name' => 'Ф', 'first_name' => 'И', 'is_student' => 1,
			'created_at' => '2026-09-01 00:00:00', 'updated_at' => '2026-09-01 00:00:00',
		) );
	}

	private function callbacks(): StudentGroupCallbacks {
		$users = $this->createStub( UserRepository::class );
		$users->method( 'searchByRole' )->willReturn( array(
			new UserDTO( 11, 'a@example.org', 'Иванов Иван', UserRole::FSStudent ),
			new UserDTO( 12, '', 'Ученик#15', UserRole::FSStudent ),
			new UserDTO( 13, 'c@example.org', 'Петров Пётр', UserRole::FSStudent ),
		) );

		$persons = $this->createStub( PersonRepository::class );
		$persons->method( 'findByWpUserId' )->willReturnCallback(
			fn ( int $userId ): ?PersonDTO => $this->person( 100 + $userId, $userId )
		);

		$records = $this->createStub( StudentRecordRepository::class );
		$records->method( 'existsActive' )->willReturn( false );
		$records->method( 'findActiveByStudent' )->willReturnCallback(
			fn ( int $personId ): array => 112 === $personId ? array( $this->record( 31, $personId ) ) : array( $this->record( 7, $personId ) )
		);

		return new StudentGroupCallbacks(
			$this->createStub( GroupsRepository::class ),
			$records,
			$persons,
			$this->createStub( LogEventDispatcherInterface::class ),
			$users,
			$this->createStub( OpenGroupEnrollmentService::class ),
			$this->createStub( GroupModeService::class ),
			new ServiceGroupResolver()
		);
	}

	public function test_students_who_live_only_in_a_service_group_are_not_offered(): void {
		$_POST = array( 'group_id' => '5', 'query' => 'ч' );

		$response = fs_test_capture_json( fn() => $this->callbacks()->ajaxSearchStudentsForGroup() );

		self::assertTrue( $response->success );
		self::assertSame( array( 111, 113 ), array_column( $response->payload, 'person_id' ) );
		self::assertSame( array( 'Иванов Иван', 'Петров Пётр' ), array_column( $response->payload, 'name' ) );
	}
}
