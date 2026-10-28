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
use Inc\Enums\Profile\NotificationType;
use Inc\Managers\Course\CourseManager;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Email\EmailService;
use Inc\Services\Enrollment\OpenGroupEnrollmentService;
use Inc\Services\Group\ServiceGroupResolver;
use Inc\Services\Exam\ExamAudienceResolver;
use Inc\Services\Profile\NotificationService;
use PHPUnit\Framework\TestCase;

class OpenGroupEnrollmentServiceTest extends TestCase {

	private const NOW = '2024-06-01 00:00:00';

	private StudentRecordRepository&\PHPUnit\Framework\MockObject\MockObject $records;
	private GroupsRepository&\PHPUnit\Framework\MockObject\MockObject $groups;
	private LogEventDispatcherInterface&\PHPUnit\Framework\MockObject\MockObject $dispatcher;
	private PersonRepository&\PHPUnit\Framework\MockObject\MockObject $persons;
	private CourseManager&\PHPUnit\Framework\MockObject\MockObject $courses;
	private EmailService&\PHPUnit\Framework\MockObject\MockObject $email;
	private ExamAudienceResolver&\PHPUnit\Framework\MockObject\MockObject $audience;
	private NotificationService&\PHPUnit\Framework\MockObject\MockObject $notifications;
	private OpenGroupEnrollmentService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->records       = $this->createMock( StudentRecordRepository::class );
		$this->groups        = $this->createMock( GroupsRepository::class );
		$this->dispatcher    = $this->createMock( LogEventDispatcherInterface::class );
		$this->persons       = $this->createMock( PersonRepository::class );
		$this->courses       = $this->createMock( CourseManager::class );
		$this->email         = $this->createMock( EmailService::class );
		$this->audience      = $this->createMock( ExamAudienceResolver::class );
		$this->notifications = $this->createMock( NotificationService::class );

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
			$this->audience,
			$this->notifications,
			new ServiceGroupResolver(),
		);

		$GLOBALS['_fs_test_actions'] = array();
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

	public function test_open_group_still_fires_enrolled_hook(): void {
		$this->setupGroup( 'open' );
		$this->records->method( 'existsActive' )->willReturn( false );
		$this->records->method( 'findByStudent' )->willReturn( [ $this->makeSourceRecord() ] );
		$this->records->method( 'create' )->willReturn( 123 );

		$this->service->enrollMany( [ 5 ], 1, 99 );

		self::assertContains( 'fs_lms_student_enrolled', array_column( $GLOBALS['_fs_test_actions'], 'hook' ) );
	}

	// --- общая (дополнительная) группа ---

	public function test_shared_scheduled_group_is_enrollable_without_documents(): void {
		$this->setupGroup( 'scheduled', shared: true );
		$this->records->method( 'existsActive' )->willReturn( false );
		$this->records->method( 'findByStudent' )->willReturn( [ $this->makeSourceRecord() ] );

		$this->records->expects( self::once() )
			->method( 'create' )
			->with( self::callback(
				static fn( StudentRecordInputDTO $dto ) => null === $dto->contractNo
					&& null === $dto->orderNo
					&& 1 === $dto->groupId
					&& 7 === $dto->parentPersonId
			) )
			->willReturn( 123 );

		$summary = $this->service->enrollMany( [ 5 ], 1, 99 );

		self::assertSame( [ 'added' => 1, 'skipped' => 0 ], $summary );
	}

	public function test_shared_group_sends_no_course_email_and_no_enrolled_hook(): void {
		$this->setupGroup( 'scheduled', shared: true );
		$this->records->method( 'existsActive' )->willReturn( false );
		$this->records->method( 'findByStudent' )->willReturn( [ $this->makeSourceRecord() ] );
		$this->records->method( 'create' )->willReturn( 123 );

		$this->email->expects( self::never() )->method( 'sendCourseGranted' );
		// Журнал аудита остаётся: «создание записи ⇒ событие».
		$this->dispatcher->expects( self::once() )->method( 'dispatch' )->with( LogEvent::StudentEnrolled, self::anything() );

		$this->service->enrollMany( [ 5 ], 1, 99 );

		self::assertNotContains( 'fs_lms_student_enrolled', array_column( $GLOBALS['_fs_test_actions'], 'hook' ) );
	}

	public function test_shared_group_pushes_one_summary_to_teacher_for_the_whole_batch(): void {
		$this->setupGroup( 'scheduled', shared: true );
		$this->records->method( 'existsActive' )->willReturn( false );
		$this->records->method( 'findByStudent' )->willReturn( [ $this->makeSourceRecord() ] );
		$this->records->method( 'create' )->willReturn( 123 );
		$this->notifications->method( 'groupTeacherUserId' )->willReturn( 55 );
		$this->notifications->method( 'groupName' )->willReturn( 'Общая' );

		$this->notifications->expects( self::once() )
			->method( 'push' )
			->with(
				[ 55 ],
				NotificationType::StudentJoined,
				self::stringStartsWith( 'joined_bulk:1:' ),
				self::callback( static fn( array $p ) => 'Добавлено учеников: 3' === $p['student_name'] && 'Общая' === $p['group_name'] ),
				self::anything(),
				1
			);

		$this->service->enrollMany( [ 5, 6, 7 ], 1, 99 );
	}

	public function test_shared_group_pushes_nothing_when_nobody_was_added(): void {
		$this->setupGroup( 'scheduled', shared: true );
		$this->records->method( 'existsActive' )->willReturn( true );
		$this->notifications->expects( self::never() )->method( 'push' );

		$summary = $this->service->enrollMany( [ 5, 6 ], 1, 99 );

		self::assertSame( [ 'added' => 0, 'skipped' => 2 ], $summary );
	}

	// --- массовое добавление: ученики предмета ---

	public function test_plan_from_subject_lists_students_not_yet_in_group(): void {
		$this->setupGroup( 'scheduled', shared: true );
		$this->audience->expects( self::once() )->method( 'studentPersonIds' )->with( 'inf_ege' )->willReturn( [ 5, 6, 7 ] );
		$this->records->method( 'findActiveByGroupId' )->with( 1 )->willReturn( [ $this->makeSourceRecord( 6 ) ] );

		$plan = $this->service->planFromSubject( 'inf_ege', 1 );

		self::assertSame( [ 'ids' => [ 5, 7 ], 'total' => 3, 'already' => 1 ], $plan );
	}

	public function test_plan_from_subject_rejects_regular_scheduled_group(): void {
		$this->setupGroup( 'scheduled', shared: false );
		$this->audience->expects( self::never() )->method( 'studentPersonIds' );

		$this->expectException( \InvalidArgumentException::class );
		$this->service->planFromSubject( 'inf_ege', 1 );
	}

	// --- служебные группы (Ш4) ---

	protected function tearDown(): void {
		unset( $GLOBALS['_fs_test_filter_returns'] );
		parent::tearDown();
	}

	public function test_enroll_many_refuses_a_service_group(): void {
		$GLOBALS['_fs_test_filter_returns']['fs_lms_service_group_ids'] = array( 1 );
		$this->setupGroup( 'open' );
		$this->records->expects( $this->never() )->method( 'create' );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'В служебную группу учеников не добавляют.' );
		$this->service->enrollMany( array( 5 ), 1, 99 );
	}

	public function test_plan_from_subject_refuses_a_service_group(): void {
		$GLOBALS['_fs_test_filter_returns']['fs_lms_service_group_ids'] = array( 1 );
		$this->setupGroup( 'open' );
		$this->audience->expects( $this->never() )->method( 'studentPersonIds' );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'В служебную группу учеников не добавляют.' );
		$this->service->planFromSubject( 'inf', 1 );
	}

	public function test_a_shared_group_is_not_service_unless_a_module_says_so(): void {
		$this->setupGroup( 'scheduled', shared: true );
		$this->records->method( 'findActiveByGroupId' )->willReturn( array() );
		$this->audience->method( 'studentPersonIds' )->willReturn( array( 5 ) );

		self::assertSame( array( 5 ), $this->service->planFromSubject( 'inf', 1 )['ids'] );
	}

	// --- helpers ---
	private function setupGroup( string $accessMode, bool $shared = false ): void {
		$group              = new \stdClass();
		$group->id          = 1;
		$group->name        = 'Открытая группа';
		$group->access_mode = $accessMode;
		$group->is_shared   = $shared ? 1 : 0;
		$this->groups->method( 'findById' )->willReturn( $group );
	}

	private function makeSourceRecord( int $studentPersonId = 5 ): StudentRecordDTO {
		return StudentRecordDTO::fromArray( [
			'id'                  => 10,
			'student_person_id'   => $studentPersonId,
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
