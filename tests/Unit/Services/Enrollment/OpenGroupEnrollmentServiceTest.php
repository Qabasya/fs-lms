<?php

declare( strict_types=1 );

namespace Unit\Services\Enrollment;

use Inc\Contracts\ClockInterface;
use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Enrollment\StudentRecordDTO;
use Inc\DTO\Enrollment\StudentRecordInputDTO;
use Inc\DTO\Person\PersonDTO;
use Inc\Enums\Enrollment\EnrollmentStatus;
use Inc\Enums\Log\LogEvent;
use Inc\Managers\Course\CourseManager;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Email\EmailService;
use Inc\Services\Enrollment\OpenGroupEnrollmentService;
use PHPUnit\Framework\TestCase;

class OpenGroupEnrollmentServiceTest extends TestCase {

	private const NOW = '2024-06-01 00:00:00';

	private StudentRecordRepository&\PHPUnit\Framework\MockObject\MockObject $records;
	private GroupsRepository&\PHPUnit\Framework\MockObject\MockObject $groups;
	private LogEventDispatcherInterface&\PHPUnit\Framework\MockObject\MockObject $dispatcher;
	private PersonRepository&\PHPUnit\Framework\MockObject\MockObject $persons;
	private CourseManager&\PHPUnit\Framework\MockObject\MockObject $courses;
	private EmailService&\PHPUnit\Framework\MockObject\MockObject $email;
	private OpenGroupEnrollmentService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->records    = $this->createMock( StudentRecordRepository::class );
		$this->groups     = $this->createMock( GroupsRepository::class );
		$this->dispatcher = $this->createMock( LogEventDispatcherInterface::class );
		$this->persons    = $this->createMock( PersonRepository::class );
		$this->courses    = $this->createMock( CourseManager::class );
		$this->email      = $this->createMock( EmailService::class );

		$clock = $this->createMock( ClockInterface::class );
		$clock->method( 'now' )->willReturn( self::NOW );

		$this->service = new OpenGroupEnrollmentService(
			$this->records,
			$this->groups,
			$this->dispatcher,
			$clock,
			$this->persons,
			$this->courses,
			$this->email,
		);
	}

	public function test_throws_when_group_not_found(): void {
		$this->groups->method( 'findById' )->willReturn( null );

		$this->expectException( \InvalidArgumentException::class );
		$this->service->enrollMany( [ 5 ], 1, 99 );
	}

	public function test_throws_for_scheduled_group(): void {
		$this->setupGroup( 'scheduled' );

		$this->expectException( \InvalidArgumentException::class );
		$this->service->enrollMany( [ 5 ], 1, 99 );
	}

	public function test_skips_student_already_active_in_group(): void {
		$this->setupGroup( 'open' );
		$this->records->method( 'existsActive' )->willReturn( true );
		$this->records->expects( self::never() )->method( 'create' );

		$summary = $this->service->enrollMany( [ 5 ], 1, 99 );

		self::assertSame( [ 'added' => 0, 'skipped' => 1 ], $summary );
	}

	public function test_skips_student_without_source_records(): void {
		$this->setupGroup( 'open' );
		$this->records->method( 'existsActive' )->willReturn( false );
		$this->records->method( 'findByStudent' )->willReturn( [] );
		$this->records->expects( self::never() )->method( 'create' );

		$summary = $this->service->enrollMany( [ 5 ], 1, 99 );

		self::assertSame( [ 'added' => 0, 'skipped' => 1 ], $summary );
	}

	public function test_enrolls_with_copied_parent_and_snapshots_without_documents(): void {
		$this->setupGroup( 'open' );
		$this->records->method( 'existsActive' )->willReturn( false );
		$this->records->method( 'findByStudent' )->willReturn( [ $this->makeSourceRecord() ] );

		$this->records->expects( self::once() )
			->method( 'create' )
			->with( self::callback(
				static fn( StudentRecordInputDTO $dto ) => 7 === $dto->parentPersonId
					&& 'Иванов' === $dto->snapshotLastName
					&& null === $dto->contractNo
					&& null === $dto->orderNo
					&& 1 === $dto->groupId
					&& self::NOW === $dto->enrolledAt
					&& 99 === $dto->enrolledByUserId
			) )
			->willReturn( 123 );

		$this->dispatcher->expects( self::once() )
			->method( 'dispatch' )
			->with( LogEvent::StudentEnrolled, self::anything() );

		$summary = $this->service->enrollMany( [ 5 ], 1, 99 );

		self::assertSame( [ 'added' => 1, 'skipped' => 0 ], $summary );
	}

	public function test_deduplicates_input_ids(): void {
		$this->setupGroup( 'open' );
		$this->records->method( 'existsActive' )->willReturn( false );
		$this->records->method( 'findByStudent' )->willReturn( [ $this->makeSourceRecord() ] );
		$this->records->expects( self::once() )->method( 'create' )->willReturn( 123 );

		$summary = $this->service->enrollMany( [ 5, 5, 0 ], 1, 99 );

		self::assertSame( [ 'added' => 1, 'skipped' => 0 ], $summary );
	}

	public function test_enrolled_student_with_wp_account_gets_notification(): void {
		$this->setupGroup( 'open' );
		$this->records->method( 'existsActive' )->willReturn( false );
		$this->records->method( 'findByStudent' )->willReturn( [ $this->makeSourceRecord() ] );
		$this->records->method( 'create' )->willReturn( 123 );

		$person = new PersonDTO(
			id: 5, wpUserId: 77, lastName: 'Тестов', firstName: 'Тест', middleName: null,
			birthDate: null, isStudent: true, school: null, grade: null, expelledAt: null,
			createdAt: '2024-01-01 00:00:00', updatedAt: '2024-01-01 00:00:00',
		);
		$this->persons->method( 'find' )->with( 5 )->willReturn( $person );

		$this->email->expects( self::once() )
			->method( 'sendCourseGranted' )
			->with( 77, self::anything(), 5 );

		$this->service->enrollMany( [ 5 ], 1, 99 );
	}

	// --- helpers ---

	private function setupGroup( string $accessMode ): void {
		$group              = new \stdClass();
		$group->id          = 1;
		$group->name        = 'Открытая группа';
		$group->access_mode = $accessMode;
		$this->groups->method( 'findById' )->willReturn( $group );
	}

	private function makeSourceRecord(): StudentRecordDTO {
		return StudentRecordDTO::fromArray( [
			'id'                  => 10,
			'student_person_id'   => 5,
			'parent_person_id'    => 7,
			'group_id'            => 2,
			'snapshot_last_name'  => 'Иванов',
			'snapshot_first_name' => 'Иван',
			'snapshot_school'     => 'Школа 1',
			'snapshot_grade'      => '9',
			'contract_no'         => 'Д-42',
			'order_no'            => 'П-7',
			'status'              => EnrollmentStatus::Active->value,
			'enrolled_at'         => '2024-01-01 00:00:00',
			'created_at'          => '2024-01-01 00:00:00',
			'updated_at'          => '2024-01-01 00:00:00',
		] );
	}
}
