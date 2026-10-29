<?php

declare( strict_types=1 );

namespace Unit\Modules\Masterclass;

use Inc\Contracts\ClockInterface;
use Inc\DTO\Course\GroupLessonInputDTO;
use Inc\DTO\Course\LessonDTO;
use Inc\DTO\Person\UserDTO;
use Inc\DTO\Settings\AcademicPeriodDTO;
use Inc\Enums\Access\UserRole;
use Inc\Managers\Course\LessonManager;
use Inc\Managers\Person\UserManager;
use Inc\Modules\Masterclass\Config\MasterclassConfig;
use Inc\Modules\Masterclass\DTO\MasterclassDTO;
use Inc\Modules\Masterclass\DTO\MasterclassFunnelDTO;
use Inc\Modules\Masterclass\DTO\MasterclassInputDTO;
use Inc\Modules\Masterclass\Enums\MasterclassLogAction;
use Inc\Modules\Masterclass\Enums\MasterclassStatus;
use Inc\Modules\Masterclass\Repositories\MasterclassAccountRepository;
use Inc\Modules\Masterclass\Repositories\MasterclassRepository;
use Inc\Modules\Masterclass\Services\MasterclassAccountService;
use Inc\Modules\Masterclass\Services\MasterclassLogWriter;
use Inc\Modules\Masterclass\Services\MasterclassService;
use Inc\Repositories\OptionsRepositories\AcademicPeriodRepository;
use Inc\Repositories\OptionsRepositories\UserRepository;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Services\Course\GroupLessonUsageGuard;
use Inc\Services\Course\OpenCourseValidator;
use Inc\Services\Exam\ExamTime;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Мастер-класс: создание (группа + занятие + строка в одной транзакции), правка, продление, запрос удаления, списки для формы.
 */
class MasterclassServiceTest extends TestCase {

	private const NOW = '2026-11-10 05:00:00';

	private MasterclassRepository&MockObject $masterclasses;
	private MasterclassAccountRepository&MockObject $accounts;
	private MasterclassAccountService&MockObject $accountService;
	private GroupsRepository&MockObject $groups;
	private GroupLessonRepository&MockObject $groupLessons;
	private AcademicPeriodRepository&MockObject $periods;
	private LessonManager&MockObject $lessons;
	private OpenCourseValidator&MockObject $validator;
	private GroupLessonUsageGuard&MockObject $usage;
	private UserRepository&MockObject $teachers;
	private UserManager&MockObject $users;
	private MasterclassLogWriter&MockObject $log;
	private MasterclassService $service;

	/** @var list<string> */
	private array $calls = array();

	/** @var list<array{0: MasterclassLogAction, 1: array<string, mixed>}> */
	private array $logged = array();

	/** Записанные в БД операторы транзакции. @var list<string> */
	private array $sql = array();

	private mixed $originalWpdb;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_ajax();
		$GLOBALS['_fs_test_timezone'] = 'Europe/Moscow';

		$this->calls  = array();
		$this->logged = array();
		$this->sql    = array();

		$this->originalWpdb = $GLOBALS['wpdb'];
		$GLOBALS['wpdb']    = new class( $this->sql ) extends \wpdb {
			/** @param list<string> $sql */
			public function __construct( private array &$sql ) {}

			public function query( string $sql ): bool|int {
				$this->sql[] = $sql;
				return 1;
			}
		};

		$this->masterclasses  = $this->createMock( MasterclassRepository::class );
		$this->accounts       = $this->createMock( MasterclassAccountRepository::class );
		$this->accountService = $this->createMock( MasterclassAccountService::class );
		$this->groups         = $this->createMock( GroupsRepository::class );
		$this->groupLessons   = $this->createMock( GroupLessonRepository::class );
		$this->periods        = $this->createMock( AcademicPeriodRepository::class );
		$this->lessons        = $this->createMock( LessonManager::class );
		$this->validator      = $this->createMock( OpenCourseValidator::class );
		$this->usage          = $this->createMock( GroupLessonUsageGuard::class );
		$this->teachers       = $this->createMock( UserRepository::class );
		$this->users          = $this->createMock( UserManager::class );
		$this->log            = $this->createMock( MasterclassLogWriter::class );

		$this->log->method( 'write' )->willReturnCallback(
			function ( MasterclassLogAction $action, array $data = array() ): void {
				$this->calls[]  = 'log:' . $action->value;
				$this->logged[] = array( $action, $data );
			}
		);

		$config = $this->createStub( MasterclassConfig::class );
		$config->method( 'freezeDays' )->willReturn( 14 );
		$config->method( 'unusedPurgeDays' )->willReturn( 7 );
		$config->method( 'frozenPurgeDays' )->willReturn( 30 );

		$clock = $this->createStub( ClockInterface::class );
		$clock->method( 'now' )->willReturnCallback( static fn ( string $type = 'mysql', bool $gmt = false ): string => $gmt ? self::NOW : '2026-11-10 08:00:00' );

		$this->service = new MasterclassService(
			$this->masterclasses,
			$this->accounts,
			$this->accountService,
			$this->groups,
			$this->groupLessons,
			$this->periods,
			$this->lessons,
			$this->validator,
			$this->usage,
			$this->teachers,
			$this->users,
			$config,
			$this->log,
			new ExamTime( $clock ),
			$clock
		);
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->originalWpdb;
		unset( $GLOBALS['_fs_test_timezone'] );
		parent::tearDown();
	}

	private function input( array $override = array() ): MasterclassInputDTO {
		$data = array_merge(
			array(
				'schoolName'    => 'Школа №5',
				'subjectKey'    => 'inf',
				'lessonId'      => 11,
				'teacherUserId' => 3,
				'startsAtLocal' => '2026-11-12 09:00:00',
				'endsAtLocal'   => '2026-11-12 14:00:00',
			),
			$override
		);

		return new MasterclassInputDTO( ...$data );
	}

	private function lesson( int $id = 11, string $subject = 'inf', string $topic = 'Системы счисления', array $workRefs = array( 55, 56 ) ): LessonDTO {
		return LessonDTO::fromArray( array(
			'id'          => $id,
			'subject_key' => $subject,
			'topic'       => $topic,
			'status'      => 'publish',
			'steps'       => array_map(
				static fn ( int $ref ): array => array( 'key' => 's' . $ref, 'type' => 'work', 'payload' => array( 'ref' => $ref ) ),
				$workRefs
			),
		) );
	}

	private function teacher( int $id = 3 ): \WP_User {
		$user        = new \WP_User();
		$user->ID    = $id;
		$user->roles = array( UserRole::FSTeacher->value );

		return $user;
	}

	/** Всё, что нужно успешному `create()`: пригодный урок, преподаватель, период. */
	private function happyPath(): void {
		$this->lessons->method( 'get' )->willReturn( $this->lesson() );
		$this->lessons->method( 'isPublished' )->willReturn( true );
		$this->validator->method( 'lessonProblems' )->willReturn( array() );
		$this->users->method( 'find' )->willReturn( $this->teacher() );
		$this->periods->method( 'getCurrentPeriod' )->willReturn( new AcademicPeriodDTO( '2026_2027', '2026/2027', '2026-09-01', '2027-05-31', true ) );
		$this->groups->method( 'existsByNameAndPeriod' )->willReturn( false );
	}

	/** @param array<string, mixed> $override */
	private function masterclass( array $override = array() ): MasterclassDTO {
		return MasterclassDTO::fromArray( array_merge(
			array(
				'id'              => 7,
				'school_name'     => 'Школа №5',
				'subject_key'     => 'inf',
				'lesson_id'       => 11,
				'group_id'        => 31,
				'group_lesson_id' => 41,
				'teacher_user_id' => 3,
				'starts_at'       => '2026-11-12 06:00:00',
				'ends_at'         => '2026-11-12 11:00:00',
				'freeze_at'       => '2026-11-26 11:00:00',
				'unused_purge_at' => '2026-11-19 11:00:00',
				'purge_at'        => '2026-12-26 11:00:00',
				'created_at'      => '2026-11-01 00:00:00',
				'updated_at'      => '2026-11-01 00:00:00',
			),
			$override
		) );
	}

	// ─── create(): проверки ────────────────────────────────────────────────

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function invalidInputs(): array {
		return array(
			'пустая школа'          => array( array( 'schoolName' => '   ' ), 'школ' ),
			'конец равен началу'    => array( array( 'endsAtLocal' => '2026-11-12 09:00:00' ), 'позже' ),
			'конец раньше начала'   => array( array( 'endsAtLocal' => '2026-11-12 08:00:00' ), 'позже' ),
			'школа длиннее 200'      => array( array( 'schoolName' => str_repeat( 'ш', 201 ) ), '200' ),
			'преподаватель не выбран' => array( array( 'teacherUserId' => 0 ), 'преподавател' ),
			'дата не в формате'     => array( array( 'startsAtLocal' => 'завтра' ), 'дат' ),
			'несуществующая дата'   => array( array( 'startsAtLocal' => '2026-02-30 09:00:00' ), 'дат' ),
		);
	}

	/**
	 * @param array<string, mixed> $override
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'invalidInputs' )]
	public function test_create_rejects_invalid_input_before_touching_anything( array $override, string $messagePart ): void {
		$this->happyPath();
		$this->groups->expects( self::never() )->method( 'create' );
		$this->masterclasses->expects( self::never() )->method( 'insert' );

		try {
			$this->service->create( $this->input( $override ), 501 );
			self::fail( 'ожидалось исключение' );
		} catch ( \InvalidArgumentException $e ) {
			self::assertStringContainsString( $messagePart, $e->getMessage() );
		}
		self::assertSame( array(), $this->sql, 'транзакция не открывалась' );
	}

	public function test_create_rejects_a_lesson_that_does_not_exist(): void {
		$this->happyPath();
		$this->lessons = $this->createMock( LessonManager::class );
		$this->lessons->method( 'get' )->willReturn( null );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Урок не найден' );
		$this->rebuild()->create( $this->input(), 501 );
	}

	public function test_create_rejects_an_unpublished_lesson(): void {
		$this->lessons->method( 'get' )->willReturn( $this->lesson() );
		$this->lessons->method( 'isPublished' )->willReturn( false );
		$this->users->method( 'find' )->willReturn( $this->teacher() );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'не опубликован' );
		$this->service->create( $this->input(), 501 );
	}

	public function test_create_rejects_a_lesson_of_another_subject(): void {
		$this->lessons->method( 'get' )->willReturn( $this->lesson( 11, 'math' ) );
		$this->lessons->method( 'isPublished' )->willReturn( true );
		$this->users->method( 'find' )->willReturn( $this->teacher() );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'другого предмета' );
		$this->service->create( $this->input(), 501 );
	}

	public function test_create_rejects_a_lesson_that_needs_manual_checking_and_names_the_problems(): void {
		$this->lessons->method( 'get' )->willReturn( $this->lesson() );
		$this->lessons->method( 'isPublished' )->willReturn( true );
		$this->users->method( 'find' )->willReturn( $this->teacher() );
		$this->validator->method( 'lessonProblems' )->willReturn( array( '«Системы счисления»: задач без автопроверки — 2' ) );

		try {
			$this->service->create( $this->input(), 501 );
			self::fail( 'ожидалось исключение' );
		} catch ( \InvalidArgumentException $e ) {
			self::assertStringContainsString( 'задач без автопроверки — 2', $e->getMessage() );
		}
	}

	public function test_create_rejects_a_user_who_is_not_a_teacher(): void {
		$this->happyPath();
		$this->users = $this->createMock( UserManager::class );
		$student        = new \WP_User();
		$student->roles = array( UserRole::FSStudent->value );
		$this->users->method( 'find' )->willReturnOnConsecutiveCalls( $student, null );

		foreach ( array( 'ученик', 'нет такого' ) as $label ) {
			try {
				$this->rebuild()->create( $this->input(), 501 );
				self::fail( "{$label}: ожидалось исключение" );
			} catch ( \InvalidArgumentException $e ) {
				self::assertStringContainsString( 'преподавател', $e->getMessage() );
			}
		}
	}

	public function test_create_requires_a_current_academic_period(): void {
		$this->lessons->method( 'get' )->willReturn( $this->lesson() );
		$this->lessons->method( 'isPublished' )->willReturn( true );
		$this->validator->method( 'lessonProblems' )->willReturn( array() );
		$this->users->method( 'find' )->willReturn( $this->teacher() );
		$this->periods->method( 'getCurrentPeriod' )->willReturn( null );
		$this->groups->expects( self::never() )->method( 'create' );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'учебного периода' );
		$this->service->create( $this->input(), 501 );
	}

	// ─── create(): порядок и данные ────────────────────────────────────────

	public function test_create_makes_group_then_lesson_then_row_inside_one_transaction_and_logs_after_commit(): void {
		$this->happyPath();
		$this->groups->method( 'create' )->willReturnCallback( function (): int {
			$this->calls[] = 'group';
			return 31;
		} );
		$this->groupLessons->method( 'add' )->willReturnCallback( function (): int {
			$this->calls[] = 'lesson';
			return 41;
		} );
		$this->masterclasses->method( 'insert' )->willReturnCallback( function (): int {
			$this->calls[] = 'row';
			return 7;
		} );
		$this->masterclasses->method( 'find' )->willReturn( $this->masterclass() );

		$result = $this->service->create( $this->input(), 501 );

		self::assertSame( 7, $result->id );
		self::assertSame( array( 'group', 'lesson', 'row', 'log:masterclass_created' ), $this->calls );
		self::assertSame( array( 'START TRANSACTION', 'COMMIT' ), $this->sql, 'журнал пишется после COMMIT, вне транзакции' );
		self::assertSame( MasterclassLogAction::MasterclassCreated, $this->logged[0][0] );
		self::assertSame( 7, $this->logged[0][1]['masterclass_id'] );
		self::assertSame( 501, $this->logged[0][1]['actor'] );
	}

	public function test_created_group_is_an_open_scheduleless_group_named_by_school_and_date(): void {
		$this->happyPath();
		$data = null;
		$this->groups->method( 'create' )->willReturnCallback( function ( array $d ) use ( &$data ): int {
			$data = $d;
			return 31;
		} );
		$this->groupLessons->method( 'add' )->willReturn( 41 );
		$this->masterclasses->method( 'insert' )->willReturn( 7 );
		$this->masterclasses->method( 'find' )->willReturn( $this->masterclass() );

		$this->service->create( $this->input(), 501 );

		self::assertSame( 'Мастер-класс · Школа №5 · 12.11.2026', $data['name'] );
		self::assertSame( 'inf', $data['subject_key'] );
		self::assertSame( '2026_2027', $data['academic_period_id'] );
		self::assertSame( 3, $data['teacher_id'] );
		self::assertSame( '[]', $data['meetings'] );
		self::assertSame( 'open', $data['access_mode'] );
		self::assertArrayNotHasKey( 'course_id', $data, 'курса-обёртки нет' );
	}

	public function test_created_lesson_row_is_open_scheduled_with_a_snapshot_of_the_lessons_works(): void {
		$this->happyPath();
		$this->groups->method( 'create' )->willReturn( 31 );
		$input = null;
		$this->groupLessons->method( 'add' )->willReturnCallback( function ( GroupLessonInputDTO $dto ) use ( &$input ): int {
			$input = $dto;
			return 41;
		} );
		$this->masterclasses->method( 'insert' )->willReturn( 7 );
		$this->masterclasses->method( 'find' )->willReturn( $this->masterclass() );

		$this->service->create( $this->input(), 501 );

		self::assertSame( 31, $input->groupId );
		self::assertSame( 11, $input->lessonId );
		self::assertSame( 0, $input->position );
		self::assertSame( array( 55, 56 ), $input->workIdsSnapshot );
		self::assertSame( '2026-11-12 09:00:00', $input->scheduledAt, 'расписание занятий — местное время как есть' );
		self::assertSame( '2026-11-12 14:00:00', $input->endsAt );
		self::assertSame( 3, $input->teacherUserId );
		self::assertSame( 'open', $input->visibility );
		self::assertNull( $input->openedAt );
		self::assertSame( 501, $input->createdByUserId );
	}

	public function test_created_row_stores_utc_times_and_deadlines_counted_from_the_end(): void {
		$this->happyPath();
		$this->groups->method( 'create' )->willReturn( 31 );
		$this->groupLessons->method( 'add' )->willReturn( 41 );
		$row = null;
		$this->masterclasses->method( 'insert' )->willReturnCallback( function ( array $d ) use ( &$row ): int {
			$row = $d;
			return 7;
		} );
		$this->masterclasses->method( 'find' )->willReturn( $this->masterclass() );

		$this->service->create( $this->input(), 501 );

		self::assertSame( 'Школа №5', $row['school_name'] );
		self::assertSame( 31, $row['group_id'] );
		self::assertSame( 41, $row['group_lesson_id'] );
		self::assertSame( 11, $row['lesson_id'] );
		self::assertSame( 3, $row['teacher_user_id'] );
		self::assertSame( '2026-11-12 06:00:00', $row['starts_at'], 'местное 09:00 (Москва) → 06:00 UTC' );
		self::assertSame( '2026-11-12 11:00:00', $row['ends_at'] );
		self::assertSame( '2026-11-26 11:00:00', $row['freeze_at'], 'конец + 14 дней' );
		self::assertSame( '2026-11-19 11:00:00', $row['unused_purge_at'], 'конец + 7 дней' );
		self::assertSame( '2026-12-26 11:00:00', $row['purge_at'], 'заморозка + 30 дней' );
		self::assertSame( 501, $row['created_by_user_id'] );
		self::assertSame( self::NOW, $row['created_at'] );
		self::assertSame( self::NOW, $row['updated_at'] );
	}

	public function test_taken_group_name_gets_a_numeric_suffix(): void {
		$this->lessons->method( 'get' )->willReturn( $this->lesson() );
		$this->lessons->method( 'isPublished' )->willReturn( true );
		$this->validator->method( 'lessonProblems' )->willReturn( array() );
		$this->users->method( 'find' )->willReturn( $this->teacher() );
		$this->periods->method( 'getCurrentPeriod' )->willReturn( new AcademicPeriodDTO( '2026_2027', '2026/2027', '2026-09-01', '2027-05-31', true ) );
		$taken = array( 'Мастер-класс · Школа №5 · 12.11.2026', 'Мастер-класс · Школа №5 · 12.11.2026 (2)' );
		$this->groups->method( 'existsByNameAndPeriod' )->willReturnCallback( static fn ( string $name ): bool => in_array( $name, $taken, true ) );
		$name = null;
		$this->groups->method( 'create' )->willReturnCallback( function ( array $d ) use ( &$name ): int {
			$name = $d['name'];
			return 31;
		} );
		$this->groupLessons->method( 'add' )->willReturn( 41 );
		$this->masterclasses->method( 'insert' )->willReturn( 7 );
		$this->masterclasses->method( 'find' )->willReturn( $this->masterclass() );

		$this->service->create( $this->input(), 501 );

		self::assertSame( 'Мастер-класс · Школа №5 · 12.11.2026 (3)', $name );
	}

	public function test_first_collision_gets_suffix_two(): void {
		$this->happyPath();
		$this->groups = $this->createMock( GroupsRepository::class );
		$this->groups->method( 'existsByNameAndPeriod' )->willReturnCallback( static fn ( string $name ): bool => 'Мастер-класс · Школа №5 · 12.11.2026' === $name );
		$name = null;
		$this->groups->method( 'create' )->willReturnCallback( function ( array $d ) use ( &$name ): int {
			$name = $d['name'];
			return 31;
		} );
		$this->groupLessons->method( 'add' )->willReturn( 41 );
		$this->masterclasses->method( 'insert' )->willReturn( 7 );
		$this->masterclasses->method( 'find' )->willReturn( $this->masterclass() );

		$this->rebuild()->create( $this->input(), 501 );

		self::assertSame( 'Мастер-класс · Школа №5 · 12.11.2026 (2)', $name );
	}

	public function test_endless_name_collisions_end_with_a_message_not_a_hang(): void {
		$this->happyPath();
		$this->groups = $this->createMock( GroupsRepository::class );
		$this->groups->method( 'existsByNameAndPeriod' )->willReturn( true );
		$this->groups->expects( self::never() )->method( 'create' );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Слишком много' );
		$this->rebuild()->create( $this->input(), 501 );
	}

	public function test_a_school_name_of_exactly_the_limit_is_accepted(): void {
		$this->happyPath();
		$this->groups = $this->createMock( GroupsRepository::class );
		$this->groups->method( 'existsByNameAndPeriod' )->willReturn( false );
		$this->groups->method( 'create' )->willReturn( 31 );
		$this->groupLessons->method( 'add' )->willReturn( 41 );
		$this->masterclasses->method( 'insert' )->willReturn( 7 );
		$this->masterclasses->method( 'find' )->willReturn( $this->masterclass() );

		$this->rebuild()->create( $this->input( array( 'schoolName' => str_repeat( 'ш', 200 ) ) ), 501 );

		$this->addToAssertionCount( 1 );
	}

	public function test_failed_lesson_insert_rolls_back_and_leaves_no_row_and_no_log(): void {
		$this->happyPath();
		$this->groups->method( 'create' )->willReturn( 31 );
		$this->groupLessons->method( 'add' )->willReturn( 0 );
		$this->masterclasses->expects( self::never() )->method( 'insert' );

		try {
			$this->service->create( $this->input(), 501 );
			self::fail( 'ожидалось исключение' );
		} catch ( \RuntimeException $e ) {
			self::assertStringContainsString( 'занятие', $e->getMessage() );
		}

		self::assertSame( array( 'START TRANSACTION', 'ROLLBACK' ), $this->sql, 'группа откатывается вместе с занятием' );
		self::assertSame( array(), $this->logged );
	}

	public function test_failed_group_insert_rolls_back(): void {
		$this->happyPath();
		$this->groups->method( 'create' )->willReturn( 0 );
		$this->groupLessons->expects( self::never() )->method( 'add' );

		try {
			$this->service->create( $this->input(), 501 );
			self::fail( 'ожидалось исключение' );
		} catch ( \RuntimeException $e ) {
			self::assertStringContainsString( 'групп', $e->getMessage() );
		}

		self::assertSame( array( 'START TRANSACTION', 'ROLLBACK' ), $this->sql );
	}

	public function test_failed_row_insert_rolls_back_the_group_and_the_lesson(): void {
		$this->happyPath();
		$this->groups->method( 'create' )->willReturn( 31 );
		$this->groupLessons->method( 'add' )->willReturn( 41 );
		$this->masterclasses->method( 'insert' )->willThrowException( new \RuntimeException( 'БД недоступна' ) );

		$this->expectException( \RuntimeException::class );
		try {
			$this->service->create( $this->input(), 501 );
		} finally {
			self::assertSame( array( 'START TRANSACTION', 'ROLLBACK' ), $this->sql );
			self::assertSame( array(), $this->logged );
		}
	}

	// ─── update() ──────────────────────────────────────────────────────────

	/** Для `update()`: сохранённый мастер-класс, группа, преподаватель, пригодный урок. */
	private function updatable( array $masterclass = array() ): void {
		$this->masterclasses->method( 'find' )->willReturn( $this->masterclass( $masterclass ) );
		$this->users->method( 'find' )->willReturn( $this->teacher() );
		$this->lessons->method( 'get' )->willReturn( $this->lesson() );
		$this->lessons->method( 'isPublished' )->willReturn( true );
		$this->validator->method( 'lessonProblems' )->willReturn( array() );
		$this->groups->method( 'findById' )->willReturn( (object) array( 'id' => 31, 'academic_period_id' => '2026_2027', 'name' => 'Мастер-класс · Школа №5 · 12.11.2026' ) );
		$this->groups->method( 'existsByNameAndPeriod' )->willReturn( false );
		$this->groups->method( 'update' )->willReturn( true );
		$this->groupLessons->method( 'updateSchedule' )->willReturn( true );
	}

	public function test_update_refuses_a_purged_or_deleting_or_missing_masterclass(): void {
		$cases = array(
			'нет'      => null,
			'вычищен'  => $this->masterclass( array( 'purged_at' => '2026-12-30 00:00:00', 'group_id' => null, 'group_lesson_id' => null ) ),
			'вычищен, группа не снята' => $this->masterclass( array( 'purged_at' => '2026-12-30 00:00:00' ) ),
			'удаляется' => $this->masterclass( array( 'delete_requested_at' => '2026-11-11 00:00:00' ) ),
		);

		foreach ( $cases as $label => $found ) {
			$this->masterclasses = $this->createMock( MasterclassRepository::class );
			$this->masterclasses->method( 'find' )->willReturn( $found );
			$this->masterclasses->expects( self::never() )->method( 'update' );

			try {
				$this->rebuild()->update( 7, $this->input(), 501 );
				self::fail( "{$label}: ожидалось исключение" );
			} catch ( \InvalidArgumentException ) {
				$this->addToAssertionCount( 1 );
			}
		}
	}

	public function test_update_keeps_the_subject_fixed(): void {
		$this->updatable();

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Предмет' );
		$this->service->update( 7, $this->input( array( 'subjectKey' => 'math' ) ), 501 );
	}

	public function test_update_moves_the_window_recounts_deadlines_and_resets_the_tick_marks(): void {
		$this->updatable( array( 'frozen_at' => '2026-11-27 00:00:00', 'unused_purged_at' => '2026-11-20 00:00:00' ) );
		$schedule = null;
		$this->groupLessons->expects( self::once() )->method( 'updateSchedule' )->willReturnCallback(
			function ( int $id, ?string $at, ?int $teacher, ?string $endsAt ) use ( &$schedule ): bool {
				$schedule = array( $id, $at, $teacher, $endsAt );
				return true;
			}
		);
		$row = null;
		$this->masterclasses->expects( self::once() )->method( 'update' )->willReturnCallback(
			function ( int $id, array $d ) use ( &$row ): void {
				$row = array( $id, $d );
			}
		);

		$this->service->update( 7, $this->input( array( 'startsAtLocal' => '2026-11-13 10:00:00', 'endsAtLocal' => '2026-11-13 15:00:00' ) ), 501 );

		self::assertSame( array( 41, '2026-11-13 10:00:00', 3, '2026-11-13 15:00:00' ), $schedule );
		self::assertSame( 7, $row[0] );
		self::assertSame( '2026-11-13 07:00:00', $row[1]['starts_at'] );
		self::assertSame( '2026-11-13 12:00:00', $row[1]['ends_at'] );
		self::assertSame( '2026-11-27 12:00:00', $row[1]['freeze_at'] );
		self::assertSame( '2026-11-20 12:00:00', $row[1]['unused_purge_at'] );
		self::assertSame( '2026-12-27 12:00:00', $row[1]['purge_at'] );
		self::assertArrayHasKey( 'frozen_at', $row[1] );
		self::assertNull( $row[1]['frozen_at'] );
		self::assertArrayHasKey( 'unused_purged_at', $row[1] );
		self::assertNull( $row[1]['unused_purged_at'] );
		self::assertSame( self::NOW, $row[1]['updated_at'] );
		self::assertSame( MasterclassLogAction::MasterclassUpdated, $this->logged[0][0] );
	}

	public function test_update_of_the_school_alone_leaves_deadlines_and_tick_marks_alone(): void {
		$this->updatable( array( 'frozen_at' => '2026-11-27 00:00:00' ) );
		$row = null;
		$this->masterclasses->method( 'update' )->willReturnCallback( function ( int $id, array $d ) use ( &$row ): void {
			$row = $d;
		} );
		$renamed = null;
		$this->groups->method( 'update' )->willReturnCallback( function ( int $id, array $d ) use ( &$renamed ): bool {
			$renamed = $d;
			return true;
		} );

		$this->service->update( 7, $this->input( array( 'schoolName' => 'Лицей №9' ) ), 501 );

		self::assertSame( 'Лицей №9', $row['school_name'] );
		foreach ( array( 'freeze_at', 'unused_purge_at', 'purge_at', 'frozen_at', 'unused_purged_at', 'starts_at', 'ends_at' ) as $key ) {
			self::assertArrayNotHasKey( $key, $row, "{$key}: окно не менялось — «Продлить» не должно затираться правкой школы" );
		}
		self::assertSame( 'Мастер-класс · Лицей №9 · 12.11.2026', $renamed['name'], 'имя группы следует за школой' );
		self::assertSame( 3, $renamed['teacher_id'] );
	}

	public function test_update_syncs_a_new_teacher_to_group_lesson_and_row(): void {
		$this->updatable();
		$this->users = $this->createMock( UserManager::class );
		$this->users->method( 'find' )->willReturn( $this->teacher( 9 ) );

		$group = null;
		$this->groups = $this->createMock( GroupsRepository::class );
		$this->groups->method( 'findById' )->willReturn( (object) array( 'id' => 31, 'academic_period_id' => '2026_2027', 'name' => 'x' ) );
		$this->groups->method( 'existsByNameAndPeriod' )->willReturn( false );
		$this->groups->method( 'update' )->willReturnCallback( function ( int $id, array $d ) use ( &$group ): bool {
			$group = $d;
			return true;
		} );
		$teacherOnSchedule = null;
		$this->groupLessons = $this->createMock( GroupLessonRepository::class );
		$this->groupLessons->method( 'updateSchedule' )->willReturnCallback( function ( int $id, ?string $at, ?int $teacher ) use ( &$teacherOnSchedule ): bool {
			$teacherOnSchedule = $teacher;
			return true;
		} );
		$row = null;
		$this->masterclasses = $this->createMock( MasterclassRepository::class );
		$this->masterclasses->method( 'find' )->willReturn( $this->masterclass() );
		$this->masterclasses->method( 'update' )->willReturnCallback( function ( int $id, array $d ) use ( &$row ): void {
			$row = $d;
		} );

		$this->rebuild()->update( 7, $this->input( array( 'teacherUserId' => 9 ) ), 501 );

		self::assertSame( 9, $group['teacher_id'] );
		self::assertSame( 9, $teacherOnSchedule );
		self::assertSame( 9, $row['teacher_user_id'] );
	}

	public function test_update_changes_the_lesson_only_while_nobody_has_started_it(): void {
		$this->updatable();
		$this->usage->method( 'hasLearningData' )->with( 41 )->willReturn( false );
		$this->lessons = $this->createMock( LessonManager::class );
		$this->lessons->method( 'get' )->willReturn( $this->lesson( 12, 'inf', 'Другая тема', array( 70 ) ) );
		$this->lessons->method( 'isPublished' )->willReturn( true );
		$this->groupLessons->expects( self::once() )->method( 'setLessonId' )->with( 41, 12 )->willReturn( true );
		$this->groupLessons->expects( self::once() )->method( 'setWorkIdsSnapshot' )->with( 41, array( 70 ) )->willReturn( true );
		$row = null;
		$this->masterclasses->method( 'update' )->willReturnCallback( function ( int $id, array $d ) use ( &$row ): void {
			$row = $d;
		} );

		$this->rebuild()->update( 7, $this->input( array( 'lessonId' => 12 ) ), 501 );

		self::assertSame( 12, $row['lesson_id'] );
	}

	public function test_update_refuses_to_swap_a_lesson_that_children_already_worked_on(): void {
		$this->updatable();
		$this->usage->method( 'hasLearningData' )->willReturn( true );
		$this->groupLessons->expects( self::never() )->method( 'setLessonId' );
		$this->masterclasses->expects( self::never() )->method( 'update' );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'сменить' );
		$this->service->update( 7, $this->input( array( 'lessonId' => 12 ) ), 501 );
	}

	public function test_update_with_the_same_lesson_never_asks_about_learning_data(): void {
		$this->updatable();
		$this->usage->expects( self::never() )->method( 'hasLearningData' );

		$this->service->update( 7, $this->input(), 501 );

		$this->addToAssertionCount( 1 );
	}

	// ─── extend() ──────────────────────────────────────────────────────────

	public function test_extend_shifts_all_deadlines_by_one_difference_and_resets_the_marks(): void {
		// сейчас 2026-11-10, заморозка 2026-11-26 — в будущем: сдвиг ровно на `days` от заморозки
		$this->masterclasses->method( 'find' )->willReturn( $this->masterclass( array( 'frozen_at' => '2026-11-27 00:00:00', 'unused_purged_at' => '2026-11-20 00:00:00' ) ) );
		$row = null;
		$this->masterclasses->expects( self::once() )->method( 'update' )->willReturnCallback( function ( int $id, array $d ) use ( &$row ): void {
			$row = array( $id, $d );
		} );

		$this->service->extend( 7, 5, 501 );

		self::assertSame( 7, $row[0] );
		self::assertSame( '2026-12-01 11:00:00', $row[1]['freeze_at'] );
		self::assertSame( '2026-11-24 11:00:00', $row[1]['unused_purge_at'] );
		self::assertSame( '2026-12-31 11:00:00', $row[1]['purge_at'] );
		self::assertArrayHasKey( 'frozen_at', $row[1] );
		self::assertNull( $row[1]['frozen_at'] );
		self::assertArrayHasKey( 'unused_purged_at', $row[1] );
		self::assertNull( $row[1]['unused_purged_at'] );
		self::assertSame( MasterclassLogAction::MasterclassExtended, $this->logged[0][0] );
		self::assertSame( 5, $this->logged[0][1]['context']['days'] );
	}

	public function test_extend_of_an_already_frozen_masterclass_counts_the_days_from_now(): void {
		// заморозка была 2026-11-01, сейчас 2026-11-10 05:00 → новая заморозка 2026-11-13 05:00, остальные сроки — на ту же разницу
		$this->masterclasses->method( 'find' )->willReturn( $this->masterclass( array(
			'freeze_at'       => '2026-11-01 11:00:00',
			'unused_purge_at' => '2026-10-25 11:00:00',
			'purge_at'        => '2026-12-01 11:00:00',
		) ) );
		$row = null;
		$this->masterclasses->method( 'update' )->willReturnCallback( function ( int $id, array $d ) use ( &$row ): void {
			$row = $d;
		} );

		$this->service->extend( 7, 3, 501 );

		self::assertSame( '2026-11-13 05:00:00', $row['freeze_at'] );
		self::assertSame( '2026-11-06 05:00:00', $row['unused_purge_at'], 'на ту же разницу: +11 дней 18 часов' );
		self::assertSame( '2026-12-13 05:00:00', $row['purge_at'] );
	}

	public function test_extend_accepts_one_to_sixty_days_only(): void {
		$this->masterclasses->method( 'find' )->willReturn( $this->masterclass() );
		$this->masterclasses->expects( self::never() )->method( 'update' );

		foreach ( array( 0, -1, 61 ) as $days ) {
			try {
				$this->service->extend( 7, $days, 501 );
				self::fail( "{$days} дней должны быть отклонены" );
			} catch ( \InvalidArgumentException $e ) {
				self::assertStringContainsString( '60', $e->getMessage() );
			}
		}
	}

	public function test_extend_refuses_a_purged_or_deleting_masterclass(): void {
		$this->masterclasses->method( 'find' )->willReturnOnConsecutiveCalls(
			$this->masterclass( array( 'delete_requested_at' => '2026-11-11 00:00:00' ) ),
			$this->masterclass( array( 'purged_at' => '2026-12-30 00:00:00' ) )
		);

		foreach ( array( 'удаляется', 'вычищен' ) as $label ) {
			try {
				$this->service->extend( 7, 5, 501 );
				self::fail( "{$label}: ожидалось исключение" );
			} catch ( \InvalidArgumentException ) {
				$this->addToAssertionCount( 1 );
			}
		}
	}

	// ─── requestDelete() ───────────────────────────────────────────────────

	public function test_request_delete_marks_logs_and_reports_the_accounts_that_will_be_detached(): void {
		$this->masterclasses->method( 'find' )->willReturn( $this->masterclass() );
		$this->masterclasses->expects( self::once() )->method( 'markDeleteRequested' )->with( 7, self::NOW );
		$this->accountService->method( 'countWithLiveApplication' )->with( 7 )->willReturn( 2 );

		$detached = $this->service->requestDelete( 7, 501 );

		self::assertSame( 2, $detached );
		self::assertSame( MasterclassLogAction::DeleteRequested, $this->logged[0][0] );
		self::assertSame( 2, $this->logged[0][1]['context']['detached'] );
	}

	public function test_a_repeated_delete_request_is_harmless_and_not_logged_twice(): void {
		$this->masterclasses->method( 'find' )->willReturn( $this->masterclass( array( 'delete_requested_at' => '2026-11-09 00:00:00' ) ) );
		$this->accountService->method( 'countWithLiveApplication' )->willReturn( 1 );

		self::assertSame( 1, $this->service->requestDelete( 7, 501 ) );
		self::assertSame( array(), $this->logged );
	}

	public function test_request_delete_of_a_missing_or_purged_masterclass_is_refused(): void {
		$this->masterclasses->method( 'find' )->willReturnOnConsecutiveCalls( null, $this->masterclass( array( 'purged_at' => '2026-12-30 00:00:00', 'group_id' => null ) ) );
		$this->masterclasses->expects( self::never() )->method( 'markDeleteRequested' );

		foreach ( array( 'нет', 'вычищен' ) as $label ) {
			try {
				$this->service->requestDelete( 7, 501 );
				self::fail( "{$label}: ожидалось исключение" );
			} catch ( \InvalidArgumentException ) {
				$this->addToAssertionCount( 1 );
			}
		}
	}

	// ─── списки ────────────────────────────────────────────────────────────

	public function test_overview_adds_funnel_and_status_to_every_row(): void {
		$running = $this->masterclass( array( 'id' => 1, 'starts_at' => '2026-11-10 03:00:00', 'ends_at' => '2026-11-10 08:00:00' ) );
		$waiting = $this->masterclass( array( 'id' => 2 ) );
		$this->masterclasses->method( 'page' )->with( 2, 20 )->willReturn( array( 'rows' => array( $running, $waiting ), 'total' => 22 ) );
		$this->accounts->method( 'funnel' )->willReturnCallback(
			static fn ( int $id ): MasterclassFunnelDTO => new MasterclassFunnelDTO( 60 + $id, 0, 0, 0, 0, 0 )
		);

		$overview = $this->service->overview( 2, 20 );

		self::assertSame( 22, $overview['total'] );
		self::assertSame( $running, $overview['rows'][0]['masterclass'] );
		self::assertSame( 61, $overview['rows'][0]['funnel']->created );
		self::assertSame( MasterclassStatus::Running, $overview['rows'][0]['status'], 'идёт: 2026-11-10 05:00 UTC внутри окна 03:00–08:00' );
		self::assertSame( MasterclassStatus::Waiting, $overview['rows'][1]['status'] );
	}

	public function test_lesson_options_list_published_lessons_with_the_reasons_unusable_ones_cannot_be_chosen(): void {
		$this->lessons->expects( self::once() )->method( 'getBankBySubject' )->with( 'inf', self::callback( static fn ( array $args ): bool => 'publish' === $args['status'] ) )->willReturn( array(
			$this->lesson( 11, 'inf', 'Системы счисления' ),
			$this->lesson( 12, 'inf', 'Алгоритмы' ),
		) );
		$this->validator->method( 'lessonProblems' )->willReturnCallback(
			static fn ( LessonDTO $lesson ): array => 12 === $lesson->id ? array( '«Алгоритмы»: задач без автопроверки — 1' ) : array()
		);

		self::assertSame(
			array(
				array( 'id' => 11, 'title' => 'Системы счисления', 'problems' => array() ),
				array( 'id' => 12, 'title' => 'Алгоритмы', 'problems' => array( '«Алгоритмы»: задач без автопроверки — 1' ) ),
			),
			$this->service->lessonOptions( 'inf' )
		);
	}

	public function test_teacher_options_come_from_the_teacher_role(): void {
		$this->teachers->expects( self::once() )->method( 'getByRole' )->with( UserRole::FSTeacher )->willReturn( array(
			new UserDTO( 3, 'a@example.org', 'Иванова А.', UserRole::FSTeacher ),
			new UserDTO( 9, 'b@example.org', 'Петров Б.', UserRole::FSTeacher ),
		) );

		self::assertSame(
			array( array( 'id' => 3, 'name' => 'Иванова А.' ), array( 'id' => 9, 'name' => 'Петров Б.' ) ),
			$this->service->teacherOptions()
		);
	}

	public function test_find_delegates_to_the_repository(): void {
		$found = $this->masterclass();
		$this->masterclasses->method( 'find' )->with( 7 )->willReturn( $found );

		self::assertSame( $found, $this->service->find( 7 ) );
	}

	/** Пересобирает сервис с текущими моками (тесты, подменяющие мок после `setUp()`). */
	private function rebuild(): MasterclassService {
		$config = $this->createStub( MasterclassConfig::class );
		$config->method( 'freezeDays' )->willReturn( 14 );
		$config->method( 'unusedPurgeDays' )->willReturn( 7 );
		$config->method( 'frozenPurgeDays' )->willReturn( 30 );

		$clock = $this->createStub( ClockInterface::class );
		$clock->method( 'now' )->willReturnCallback( static fn ( string $type = 'mysql', bool $gmt = false ): string => $gmt ? self::NOW : '2026-11-10 08:00:00' );

		return new MasterclassService(
			$this->masterclasses,
			$this->accounts,
			$this->accountService,
			$this->groups,
			$this->groupLessons,
			$this->periods,
			$this->lessons,
			$this->validator,
			$this->usage,
			$this->teachers,
			$this->users,
			$config,
			$this->log,
			new ExamTime( $clock ),
			$clock
		);
	}
}
