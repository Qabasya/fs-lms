<?php

declare( strict_types=1 );

namespace Unit\Modules\Masterclass;

use Inc\Contracts\ClockInterface;
use Inc\DTO\Application\ApplicationDTO;
use Inc\DTO\Enrollment\StudentRecordInputDTO;
use Inc\DTO\Import\AccountCredentialsDTO;
use Inc\DTO\Person\PersonInputDTO;
use Inc\Enums\Enrollment\ApplicationStatus;
use Inc\Managers\Person\UserManager;
use Inc\Modules\Masterclass\DTO\MasterclassAccountDTO;
use Inc\Modules\Masterclass\DTO\MasterclassDTO;
use Inc\Modules\Masterclass\Enums\AccountDeleteReason;
use Inc\Modules\Masterclass\Enums\MasterclassAccountState;
use Inc\Modules\Masterclass\Enums\MasterclassLogAction;
use Inc\Modules\Masterclass\Repositories\MasterclassAccountRepository;
use Inc\Modules\Masterclass\Repositories\MasterclassRepository;
use Inc\Modules\Masterclass\Services\MasterclassAccountService;
use Inc\Modules\Masterclass\Services\MasterclassLogWriter;
use Inc\Repositories\WPDBRepositories\ApplicationRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Deletion\DeleteStudentEvent;
use Inc\Services\Deletion\DeletionEventDispatcher;
use Inc\Services\Enrollment\AccountProvisioningService;
use Inc\Services\Exam\ExamTime;
use Inc\Services\Person\PersonService;
use Inc\Services\Security\LoginGuardService;
use Inc\Services\Security\PasswordGeneratorService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Аккаунты мастер-класса: логин по номеру, порции, откат при сбое, флаеры, список страницы, удаление/отвязка, снятие блокировок.
 */
class MasterclassAccountServiceTest extends TestCase {

	private const NOW = '2026-11-10 08:00:00';

	private MasterclassAccountRepository&MockObject $accounts;
	private MasterclassRepository&MockObject $masterclasses;
	private AccountProvisioningService&MockObject $provisioning;
	private PersonService&MockObject $persons;
	private StudentRecordRepository&MockObject $records;
	private UserManager&MockObject $users;
	private PasswordGeneratorService&MockObject $passwords;
	private DeletionEventDispatcher&MockObject $deletion;
	private ApplicationRepository&MockObject $applications;
	private MasterclassLogWriter&MockObject $log;
	private LoginGuardService&MockObject $loginGuard;
	private MasterclassAccountService $service;

	/** Порядок вызовов зависимостей (для проверок «до» и «после»). @var list<string> */
	private array $calls = array();

	/** Записи журнала: действие + данные. @var list<array{0: MasterclassLogAction, 1: array<string, mixed>}> */
	private array $logged = array();

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_ajax();
		unset( $GLOBALS['_fs_test_timezone'] );

		$this->calls  = array();
		$this->logged = array();

		$this->accounts      = $this->createMock( MasterclassAccountRepository::class );
		$this->masterclasses = $this->createMock( MasterclassRepository::class );
		$this->provisioning  = $this->createMock( AccountProvisioningService::class );
		$this->persons       = $this->createMock( PersonService::class );
		$this->records       = $this->createMock( StudentRecordRepository::class );
		$this->users         = $this->createMock( UserManager::class );
		$this->passwords     = $this->createMock( PasswordGeneratorService::class );
		$this->deletion      = $this->createMock( DeletionEventDispatcher::class );
		$this->applications  = $this->createMock( ApplicationRepository::class );
		$this->log           = $this->createMock( MasterclassLogWriter::class );
		$this->loginGuard    = $this->createMock( LoginGuardService::class );

		$this->log->method( 'write' )->willReturnCallback(
			function ( MasterclassLogAction $action, array $data = array() ): void {
				$this->calls[]  = 'log:' . $action->value;
				$this->logged[] = array( $action, $data );
			}
		);

		$clock = $this->createStub( ClockInterface::class );
		$clock->method( 'now' )->willReturn( self::NOW );

		$this->service = new MasterclassAccountService(
			$this->accounts,
			$this->masterclasses,
			$this->provisioning,
			$this->persons,
			$this->records,
			$this->users,
			$this->passwords,
			$this->deletion,
			$this->applications,
			$this->log,
			$this->loginGuard,
			$clock,
			new ExamTime( $clock )
		);
	}

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
				'starts_at'       => '2026-11-10 06:00:00',
				'ends_at'         => '2026-11-10 11:00:00',
				'freeze_at'       => '2026-11-24 11:00:00',
				'unused_purge_at' => '2026-11-17 11:00:00',
				'purge_at'        => '2026-12-24 11:00:00',
				'created_at'      => '2026-11-01 00:00:00',
				'updated_at'      => '2026-11-01 00:00:00',
			),
			$override
		) );
	}

	private function account( int $id, array $override = array() ): MasterclassAccountDTO {
		return MasterclassAccountDTO::fromArray( array_merge(
			array(
				'id'             => $id,
				'masterclass_id' => 7,
				'user_id'        => 1000 + $id,
				'person_id'      => 2000 + $id,
				'student_record_id' => 3000 + $id,
				'state'          => 'active',
				'created_at'     => '2026-11-01 00:00:00',
				'updated_at'     => '2026-11-01 00:00:00',
			),
			$override
		) );
	}

	/** Настраивает цепочку успешного создания аккаунтов: номера выдаются подряд с `$firstNumber`. */
	private function expectSuccessfulChain( int $firstNumber ): void {
		$next = $firstNumber;
		$this->accounts->method( 'insertPending' )->willReturnCallback(
			function ( int $masterclassId, string $nowUtc ) use ( &$next ): int {
				$this->calls[] = 'insertPending';
				return $next++;
			}
		);
		$this->users->method( 'findByLogin' )->willReturn( null );
		$this->passwords->method( 'generateReadable' )->willReturn( 'Abcd2345' );
		$this->persons->method( 'createOrFindBy' )->willReturnCallback(
			function ( PersonInputDTO $input ): int {
				$this->calls[] = 'createPerson';
				return 2000 + (int) substr( $input->lastName, strlen( 'Ученик#' ) );
			}
		);
		$this->provisioning->method( 'provisionStudent' )->willReturnCallback(
			function ( int $personId, PersonInputDTO $data, string $login, string $password ): AccountCredentialsDTO {
				$this->calls[] = 'provision:' . $login;
				return new AccountCredentialsDTO( $personId - 1000, $login, $password, true );
			}
		);
		$this->records->method( 'create' )->willReturnCallback(
			function ( StudentRecordInputDTO $dto ): int {
				$this->calls[] = 'createRecord';
				return $dto->studentPersonId + 1000;
			}
		);
	}

	public function test_login_is_prefix_plus_number(): void {
		self::assertSame( 'student-1', MasterclassAccountService::loginFor( 1 ) );
		self::assertSame( 'student-1507', MasterclassAccountService::loginFor( 1507 ) );
	}

	public function test_chunk_size_must_be_between_one_and_the_chunk_limit(): void {
		$this->masterclasses->method( 'find' )->willReturn( $this->masterclass() );

		foreach ( array( 0, -1, MasterclassAccountService::CHUNK_MAX + 1 ) as $count ) {
			try {
				$this->service->createChunk( 7, $count, 1 );
				self::fail( "count={$count} должен быть отклонён" );
			} catch ( \InvalidArgumentException $e ) {
				self::assertStringContainsString( (string) MasterclassAccountService::CHUNK_MAX, $e->getMessage() );
			}
		}
	}

	public function test_chunk_refuses_missing_purged_deleting_or_groupless_masterclass(): void {
		$cases = array(
			'нет такого'        => null,
			'вычищен'           => $this->masterclass( array( 'purged_at' => '2026-12-30 00:00:00', 'group_id' => null ) ),
			'вычищен, группа не снята' => $this->masterclass( array( 'purged_at' => '2026-12-30 00:00:00' ) ),
			'помечен к удалению' => $this->masterclass( array( 'delete_requested_at' => '2026-11-12 00:00:00' ) ),
			'без группы'        => $this->masterclass( array( 'group_id' => null ) ),
		);

		foreach ( $cases as $label => $masterclass ) {
			$this->masterclasses = $this->createMock( MasterclassRepository::class );
			$this->masterclasses->method( 'find' )->willReturn( $masterclass );
			$this->accounts = $this->createMock( MasterclassAccountRepository::class );
			$this->accounts->expects( self::never() )->method( 'insertPending' );
			$service = $this->rebuild();

			try {
				$service->createChunk( 7, 1, 1 );
				self::fail( "{$label}: ожидалось исключение" );
			} catch ( \InvalidArgumentException ) {
				$this->addToAssertionCount( 1 );
			}
		}
	}

	/** Собирает сервис заново с текущими моками репозиториев (нужно тестам, которые подменяют их внутри). */
	private function rebuild(): MasterclassAccountService {
		$clock = $this->createStub( ClockInterface::class );
		$clock->method( 'now' )->willReturn( self::NOW );

		return new MasterclassAccountService(
			$this->accounts,
			$this->masterclasses,
			$this->provisioning,
			$this->persons,
			$this->records,
			$this->users,
			$this->passwords,
			$this->deletion,
			$this->applications,
			$this->log,
			$this->loginGuard,
			$clock,
			new ExamTime( $clock )
		);
	}

	public function test_chunk_creates_the_whole_chain_per_account_with_number_equal_to_row_id(): void {
		$this->masterclasses->method( 'find' )->willReturn( $this->masterclass() );
		$this->expectSuccessfulChain( 15 );

		$attached = array();
		$this->accounts->method( 'attach' )->willReturnCallback(
			function ( int $id, int $userId, int $personId, int $recordId ) use ( &$attached ): void {
				$this->calls[] = 'attach:' . $id;
				$attached[]    = array( $id, $userId, $personId, $recordId );
			}
		);

		$created = $this->service->createChunk( 7, 2, 501 );

		self::assertSame( 2, $created );
		self::assertSame(
			array(
				'insertPending', 'createPerson', 'provision:student-15', 'createRecord', 'attach:15',
				'insertPending', 'createPerson', 'provision:student-16', 'createRecord', 'attach:16',
				'log:accounts_created',
			),
			$this->calls,
			'на аккаунт: номер → физлицо → учётка → запись → привязка; журнал один, в конце'
		);
		self::assertSame( array( 15, 1015, 2015, 3015 ), $attached[0] );
		self::assertSame( array( 16, 1016, 2016, 3016 ), $attached[1] );
	}

	public function test_account_person_record_and_credentials_carry_the_numbered_name(): void {
		$this->masterclasses->method( 'find' )->willReturn( $this->masterclass() );
		$this->accounts->method( 'insertPending' )->willReturn( 15 );
		$this->users->method( 'findByLogin' )->willReturn( null );
		$this->passwords->method( 'generateReadable' )->willReturn( 'Abcd2345' );

		$personInput = null;
		$this->persons->method( 'createOrFindBy' )->willReturnCallback(
			function ( PersonInputDTO $input ) use ( &$personInput ): int {
				$personInput = $input;
				return 2015;
			}
		);

		$provisionArgs = null;
		$this->provisioning->method( 'provisionStudent' )->willReturnCallback(
			function ( int $personId, PersonInputDTO $data, string $login, string $password ) use ( &$provisionArgs ): AccountCredentialsDTO {
				$provisionArgs = array( $personId, $data, $login, $password );
				return new AccountCredentialsDTO( 1015, $login, $password, true );
			}
		);

		$record = null;
		$this->records->method( 'create' )->willReturnCallback(
			function ( StudentRecordInputDTO $dto ) use ( &$record ): int {
				$record = $dto;
				return 3015;
			}
		);

		$this->service->createChunk( 7, 1, 501 );

		self::assertSame( 'Ученик#15', $personInput->lastName );
		self::assertSame( '', $personInput->firstName );
		self::assertSame( '', $personInput->docNumber, 'пустой номер документа: физлицо не склеивается с чужим' );
		self::assertSame( array( 2015, 'student-15', 'Abcd2345' ), array( $provisionArgs[0], $provisionArgs[2], $provisionArgs[3] ) );

		self::assertTrue( $record->isTrial, 'запись временная' );
		self::assertSame( 31, $record->groupId );
		self::assertSame( 0, $record->parentPersonId );
		self::assertSame( 2015, $record->studentPersonId );
		self::assertSame( 'active', $record->status );
		self::assertSame( 'Ученик#15', $record->snapshotLastName );
		self::assertSame( self::NOW, $record->enrolledAt, 'время записи — UTC из часов' );
	}

	public function test_chunk_logs_the_count_without_passwords(): void {
		$this->masterclasses->method( 'find' )->willReturn( $this->masterclass() );
		$this->expectSuccessfulChain( 1 );

		$this->service->createChunk( 7, 3, 501 );

		[ $action, $data ] = $this->logged[0];
		self::assertSame( MasterclassLogAction::AccountsCreated, $action );
		self::assertSame( 7, $data['masterclass_id'] );
		self::assertSame( 501, $data['actor'] );
		self::assertSame( 3, $data['context']['count'] );
		self::assertStringNotContainsString( 'Abcd2345', (string) json_encode( $data ), 'пароль в журнал не попадает' );
	}

	public function test_taken_login_burns_the_number_and_continues_the_chunk(): void {
		$this->masterclasses->method( 'find' )->willReturn( $this->masterclass() );

		$next = 20;
		$this->accounts->method( 'insertPending' )->willReturnCallback( static function () use ( &$next ): int {
			return $next++;
		} );
		$this->users->method( 'findByLogin' )->willReturnCallback(
			static fn ( string $login ) => 'student-20' === $login ? new \WP_User() : null
		);
		$this->passwords->method( 'generateReadable' )->willReturn( 'Abcd2345' );
		$this->persons->method( 'createOrFindBy' )->willReturn( 2021 );
		$this->provisioning->method( 'provisionStudent' )->willReturn( new AccountCredentialsDTO( 1021, 'student-21', 'x', true ) );
		$this->records->method( 'create' )->willReturn( 3021 );

		$burned = array();
		$this->accounts->method( 'markDeleted' )->willReturnCallback(
			function ( int $id, AccountDeleteReason $reason ) use ( &$burned ): bool {
				$burned[] = array( $id, $reason );
				return true;
			}
		);
		$this->persons->expects( self::once() )->method( 'createOrFindBy' );

		$created = $this->service->createChunk( 7, 2, 501 );

		self::assertSame( 1, $created, 'занятый логин не считается созданным' );
		self::assertSame( array( array( 20, AccountDeleteReason::CreateFailed ) ), $burned );
	}

	public function test_failure_after_person_creation_rolls_back_through_the_student_deletion_event(): void {
		$this->masterclasses->method( 'find' )->willReturn( $this->masterclass() );
		$this->accounts->method( 'insertPending' )->willReturn( 15 );
		$this->users->method( 'findByLogin' )->willReturn( null );
		$this->passwords->method( 'generateReadable' )->willReturn( 'Abcd2345' );
		$this->persons->method( 'createOrFindBy' )->willReturn( 2015 );
		$this->provisioning->method( 'provisionStudent' )->willThrowException( new \RuntimeException( 'логин занят' ) );
		$this->accounts->expects( self::never() )->method( 'attach' );
		$this->accounts->expects( self::once() )->method( 'markDeleted' )->with( 15, AccountDeleteReason::CreateFailed, self::NOW )->willReturn( true );

		$events = array();
		$this->deletion->method( 'dispatch' )->willReturnCallback( static function ( object $event ) use ( &$events ): void {
			$events[] = $event;
		} );

		$created = $this->service->createChunk( 7, 1, 501 );

		self::assertSame( 0, $created );
		self::assertCount( 1, $events );
		self::assertInstanceOf( DeleteStudentEvent::class, $events[0] );
		self::assertSame( 2015, $events[0]->studentPersonId, 'созданное физлицо удаляется вместе с тем, что успело появиться' );
		self::assertSame( 501, $events[0]->actorId );
		self::assertSame( array(), $this->logged, 'ни одного созданного — нечего писать в журнал' );
	}

	public function test_record_insert_returning_zero_rolls_back_too(): void {
		$this->masterclasses->method( 'find' )->willReturn( $this->masterclass() );
		$this->accounts->method( 'insertPending' )->willReturn( 15 );
		$this->users->method( 'findByLogin' )->willReturn( null );
		$this->passwords->method( 'generateReadable' )->willReturn( 'Abcd2345' );
		$this->persons->method( 'createOrFindBy' )->willReturn( 2015 );
		$this->provisioning->method( 'provisionStudent' )->willReturn( new AccountCredentialsDTO( 1015, 'student-15', 'x', true ) );
		$this->records->method( 'create' )->willReturn( 0 );
		$this->accounts->expects( self::never() )->method( 'attach' );
		$this->accounts->expects( self::once() )->method( 'markDeleted' )->willReturn( true );
		$this->deletion->expects( self::once() )->method( 'dispatch' )->with( self::isInstanceOf( DeleteStudentEvent::class ) );

		self::assertSame( 0, $this->service->createChunk( 7, 1, 501 ) );
	}

	public function test_failure_before_the_person_exists_has_nothing_to_roll_back(): void {
		$this->masterclasses->method( 'find' )->willReturn( $this->masterclass() );
		$this->accounts->method( 'insertPending' )->willReturn( 15 );
		$this->users->method( 'findByLogin' )->willReturn( null );
		$this->passwords->method( 'generateReadable' )->willReturn( 'Abcd2345' );
		$this->persons->method( 'createOrFindBy' )->willThrowException( new \RuntimeException( 'БД недоступна' ) );
		$this->deletion->expects( self::never() )->method( 'dispatch' );
		$this->accounts->expects( self::once() )->method( 'markDeleted' )->with( 15, AccountDeleteReason::CreateFailed, self::NOW )->willReturn( true );

		self::assertSame( 0, $this->service->createChunk( 7, 1, 501 ) );
	}

	public function test_a_failing_rollback_does_not_stop_the_chunk(): void {
		$this->masterclasses->method( 'find' )->willReturn( $this->masterclass() );
		$next = 15;
		$this->accounts->method( 'insertPending' )->willReturnCallback( static function () use ( &$next ): int {
			return $next++;
		} );
		$this->users->method( 'findByLogin' )->willReturn( null );
		$this->passwords->method( 'generateReadable' )->willReturn( 'Abcd2345' );
		$this->persons->method( 'createOrFindBy' )->willReturnCallback( static fn ( PersonInputDTO $i ): int => 2000 + (int) substr( $i->lastName, 14 ) );
		$this->provisioning->method( 'provisionStudent' )->willReturnCallback(
			static function ( int $personId, PersonInputDTO $d, string $login ): AccountCredentialsDTO {
				if ( 'student-15' === $login ) {
					throw new \RuntimeException( 'сбой' );
				}
				return new AccountCredentialsDTO( $personId - 1000, $login, 'x', true );
			}
		);
		$this->records->method( 'create' )->willReturn( 3016 );
		$this->deletion->method( 'dispatch' )->willThrowException( new \RuntimeException( 'и откат упал' ) );
		$this->accounts->method( 'markDeleted' )->willReturn( true );

		self::assertSame( 1, $this->service->createChunk( 7, 2, 501 ), 'второй аккаунт создан, несмотря на упавший откат первого' );
	}

	public function test_flyers_cover_only_live_active_accounts_with_a_readable_password(): void {
		$this->masterclasses->method( 'find' )->willReturn( $this->masterclass() );
		$this->accounts->method( 'listByMasterclass' )->willReturn( array(
			$this->account( 1 ),
			$this->account( 2, array( 'deleted_at' => '2026-11-11 00:00:00', 'delete_reason' => 'unused' ) ),
			$this->account( 3, array( 'state' => 'applied' ) ),
			$this->account( 4, array( 'user_id' => null ) ),
			$this->account( 5 ),
			$this->account( 6 ),
		) );
		$this->passwords->method( 'getCredentials' )->willReturnCallback(
			static fn ( int $userId ): ?array => 1005 === $userId ? null : array( 'login' => 'x', 'password' => 'Pw' . $userId )
		);

		$flyers = $this->service->flyers( 7, 501 );

		self::assertSame( array( 1, 6 ), array_map( static fn ( $f ): int => $f->number, $flyers ), 'удалённые, поданные, без учётки и без читаемого пароля — не печатаются' );
		self::assertSame( 'student-1', $flyers[0]->login );
		self::assertSame( 'Pw1001', $flyers[0]->password );
		self::assertSame( 'Школа №5', $flyers[0]->schoolName );
		self::assertStringContainsString( '/sign-in/', $flyers[0]->loginUrl );
	}

	public function test_flyers_log_the_count_but_never_the_passwords(): void {
		$this->masterclasses->method( 'find' )->willReturn( $this->masterclass() );
		$this->accounts->method( 'listByMasterclass' )->willReturn( array( $this->account( 1 ), $this->account( 2 ) ) );
		$this->passwords->method( 'getCredentials' )->willReturn( array( 'login' => 'x', 'password' => 'Secret77' ) );

		$this->service->flyers( 7, 501 );

		[ $action, $data ] = $this->logged[0];
		self::assertSame( MasterclassLogAction::FlyersPrinted, $action );
		self::assertSame( 2, $data['context']['count'] );
		self::assertSame( 501, $data['actor'] );
		self::assertStringNotContainsString( 'Secret77', (string) json_encode( $data ) );
	}

	public function test_flyers_for_a_missing_masterclass_are_refused(): void {
		$this->masterclasses->method( 'find' )->willReturn( null );

		$this->expectException( \InvalidArgumentException::class );
		$this->service->flyers( 7, 501 );
	}

	public function test_page_rows_have_numbers_logins_labels_and_local_times_but_no_secrets(): void {
		$GLOBALS['_fs_test_timezone'] = 'Europe/Moscow';
		$this->masterclasses->method( 'find' )->willReturn( $this->masterclass() );
		$this->accounts->expects( self::once() )->method( 'pageByMasterclass' )->with( 7, 2, 50, true )->willReturn( array(
			'rows'  => array(
				$this->account( 51, array( 'first_login_at' => '2026-11-10 06:30:00', 'home_at' => '2026-11-10 15:00:00', 'application_id' => 9, 'state' => 'applied' ) ),
				$this->account( 52, array( 'deleted_at' => '2026-11-18 09:00:00', 'delete_reason' => 'unused' ) ),
			),
			'total' => 52,
		) );

		$page = $this->service->page( 7, 2, 50, true );

		self::assertSame( 52, $page['total'] );
		self::assertSame( 7, $page['masterclass']->id );
		$first = $page['rows'][0];
		self::assertSame( 51, $first['number'] );
		self::assertSame( 'student-51', $first['login'] );
		self::assertSame( MasterclassAccountState::Applied->label(), $first['state'] );
		self::assertSame( '2026-11-10 09:30:00', $first['firstLoginLocal'], 'UTC → местное (Москва, +3)' );
		self::assertSame( '2026-11-10 18:00:00', $first['homeLocal'] );
		self::assertNull( $first['contactLocal'] );
		self::assertNull( $first['deletedLocal'] );
		self::assertNull( $first['deleteReason'] );
		self::assertTrue( $first['hasApplication'] );

		$second = $page['rows'][1];
		self::assertSame( '2026-11-18 12:00:00', $second['deletedLocal'] );
		self::assertSame( AccountDeleteReason::Unused->label(), $second['deleteReason'] );
		self::assertFalse( $second['hasApplication'] );

		$flat = (string) json_encode( $page['rows'], JSON_UNESCAPED_UNICODE );
		self::assertDoesNotMatchRegularExpression( '/pass|pwd|email|user_id|person_id/i', $flat, 'в строке нет паролей и ПД' );
	}

	public function test_page_of_a_missing_masterclass_is_refused_and_of_a_purged_one_is_empty(): void {
		$this->masterclasses->method( 'find' )->willReturnOnConsecutiveCalls( null, $this->masterclass( array( 'purged_at' => '2026-12-30 00:00:00', 'group_id' => null ) ) );
		$this->accounts->expects( self::never() )->method( 'pageByMasterclass' );

		try {
			$this->service->page( 7 );
			self::fail( 'нет мастер-класса — исключение' );
		} catch ( \InvalidArgumentException ) {
			$this->addToAssertionCount( 1 );
		}

		$purged = $this->service->page( 7 );
		self::assertSame( array(), $purged['rows'] );
		self::assertSame( 0, $purged['total'] );
	}

	public function test_live_account_lookup_skips_deleted_and_enrolled(): void {
		$this->accounts->method( 'findByUserId' )->willReturnMap( array(
			array( 1, $this->account( 1 ) ),
			array( 2, $this->account( 2, array( 'deleted_at' => '2026-11-11 00:00:00' ) ) ),
			array( 3, $this->account( 3, array( 'state' => 'enrolled' ) ) ),
			array( 4, $this->account( 4, array( 'state' => 'applied' ) ) ),
		) );

		self::assertSame( 1, $this->service->findLiveByUserId( 1 )?->id );
		self::assertNull( $this->service->findLiveByUserId( 2 ) );
		self::assertNull( $this->service->findLiveByUserId( 3 ) );
		self::assertSame( 4, $this->service->findLiveByUserId( 4 )?->id, 'подавший заявку ещё живой' );
		self::assertNull( $this->service->findLiveByUserId( 99 ) );
	}

	public function test_player_url_points_to_the_group_lesson_and_is_null_without_them(): void {
		$this->accounts->method( 'findByUserId' )->willReturnMap( array(
			array( 1, $this->account( 1 ) ),
			array( 2, $this->account( 2, array( 'masterclass_id' => 8 ) ) ),
			array( 3, $this->account( 3, array( 'masterclass_id' => 9 ) ) ),
		) );
		$this->masterclasses->method( 'find' )->willReturnMap( array(
			array( 7, $this->masterclass() ),
			array( 8, $this->masterclass( array( 'id' => 8, 'group_id' => null, 'group_lesson_id' => null ) ) ),
			array( 9, $this->masterclass( array( 'id' => 9, 'group_lesson_id' => null ) ) ),
		) );

		$url = $this->service->playerUrlFor( 1 );

		self::assertNotNull( $url );
		self::assertStringContainsString( 'gid=31', $url );
		self::assertStringContainsString( 'gl=41', $url );
		self::assertNull( $this->service->playerUrlFor( 2 ), 'мастер-класс без группы' );
		self::assertNull( $this->service->playerUrlFor( 3 ), 'группа есть, занятия нет' );
		self::assertNull( $this->service->playerUrlFor( 99 ), 'нет аккаунта' );
	}

	public function test_account_is_marked_deleted_before_the_deletion_event_fires(): void {
		$this->accounts->method( 'markDeleted' )->willReturnCallback( function (): bool {
			$this->calls[] = 'markDeleted';
			return true;
		} );
		$this->deletion->method( 'dispatch' )->willReturnCallback( function ( object $event ): void {
			$this->calls[] = 'dispatch:' . $event->studentPersonId;
		} );

		$this->service->deleteAccount( $this->account( 15 ), AccountDeleteReason::Expired, 501 );

		self::assertSame( array( 'markDeleted', 'dispatch:2015', 'log:account_deleted' ), $this->calls, 'метка — до события: обработчик deleted_user увидит помеченную строку' );
		self::assertSame( AccountDeleteReason::Expired->value, $this->logged[0][1]['context']['reason'] );
		self::assertSame( 15, $this->logged[0][1]['account_id'] );
	}

	public function test_deleting_an_already_deleted_account_does_nothing(): void {
		$this->accounts->method( 'markDeleted' )->willReturn( false );
		$this->deletion->expects( self::never() )->method( 'dispatch' );

		$this->service->deleteAccount( $this->account( 15 ), AccountDeleteReason::Expired, 501 );

		self::assertSame( array(), $this->logged );
	}

	public function test_deleting_an_account_without_a_person_only_marks_it(): void {
		$this->accounts->expects( self::once() )->method( 'markDeleted' )->willReturn( true );
		$this->deletion->expects( self::never() )->method( 'dispatch' );

		$this->service->deleteAccount( $this->account( 15, array( 'person_id' => null, 'user_id' => null ) ), AccountDeleteReason::Unused, 0 );

		self::assertCount( 1, $this->logged );
	}

	public function test_detach_drops_only_the_trial_record_and_keeps_the_account_row(): void {
		$this->records->expects( self::once() )->method( 'deleteTrialByStudent' )->with( 2015 );
		$this->accounts->expects( self::once() )->method( 'clearStudentRecord' )->with( 15 );
		$this->accounts->expects( self::never() )->method( 'markDeleted' );
		$this->deletion->expects( self::never() )->method( 'dispatch' );

		$this->service->detachAccount( $this->account( 15, array( 'application_id' => 9, 'state' => 'applied' ) ), 501 );

		self::assertSame( MasterclassLogAction::AccountDetached, $this->logged[0][0] );
	}

	public function test_live_application_means_pending_parent_ready_or_enrolling(): void {
		$statuses = array(
			1 => ApplicationStatus::PendingParent,
			2 => ApplicationStatus::ReadyForReview,
			3 => ApplicationStatus::Enrolling,
			4 => ApplicationStatus::Converted,
			5 => ApplicationStatus::Expired,
			6 => ApplicationStatus::Trash,
		);
		$this->applications->method( 'find' )->willReturnCallback(
			fn ( int $id ): ?ApplicationDTO => isset( $statuses[ $id ] ) ? $this->application( $id, $statuses[ $id ] ) : null
		);

		foreach ( array( 1, 2, 3 ) as $id ) {
			self::assertTrue( $this->service->hasLiveApplication( $this->account( 15, array( 'application_id' => $id ) ) ), "статус заявки {$id}" );
		}
		foreach ( array( 4, 5, 6, 99 ) as $id ) {
			self::assertFalse( $this->service->hasLiveApplication( $this->account( 15, array( 'application_id' => $id ) ) ), "статус заявки {$id}" );
		}
		self::assertFalse( $this->service->hasLiveApplication( $this->account( 15 ) ), 'заявки нет' );
	}

	private function application( int $id, ApplicationStatus $status ): ApplicationDTO {
		return ApplicationDTO::fromArray( array(
			'id'         => $id,
			'status'     => $status->value,
			'created_at' => '2026-11-10 00:00:00',
			'updated_at' => '2026-11-10 00:00:00',
		) );
	}

	public function test_count_with_live_application_skips_deleted_accounts_and_dead_applications(): void {
		$this->accounts->method( 'listByMasterclass' )->willReturn( array(
			$this->account( 1, array( 'application_id' => 1 ) ),
			$this->account( 2, array( 'application_id' => 2, 'deleted_at' => '2026-11-11 00:00:00' ) ),
			$this->account( 3, array( 'application_id' => 4 ) ),
			$this->account( 4 ),
		) );
		$this->applications->method( 'find' )->willReturnCallback(
			fn ( int $id ): ApplicationDTO => $this->application( $id, 4 === $id ? ApplicationStatus::Converted : ApplicationStatus::PendingParent )
		);

		self::assertSame( 1, $this->service->countWithLiveApplication( 7 ) );
	}

	public function test_unlock_resets_every_live_account_and_logs_the_count(): void {
		$this->masterclasses->method( 'find' )->willReturn( $this->masterclass() );
		$this->accounts->method( 'liveUserIds' )->with( 7 )->willReturn( array( 1001, 1002, 1005 ) );

		$unlocked = array();
		$this->loginGuard->method( 'unlockUser' )->willReturnCallback( static function ( int $userId ) use ( &$unlocked ): void {
			$unlocked[] = $userId;
		} );

		$count = $this->service->unlockLogins( 7, 501 );

		self::assertSame( 3, $count );
		self::assertSame( array( 1001, 1002, 1005 ), $unlocked );
		self::assertSame( MasterclassLogAction::LoginsUnlocked, $this->logged[0][0] );
		self::assertSame( 3, $this->logged[0][1]['context']['count'] );
	}

	public function test_unlock_of_a_missing_or_purged_masterclass_is_refused(): void {
		$this->masterclasses->method( 'find' )->willReturnOnConsecutiveCalls( null, $this->masterclass( array( 'purged_at' => '2026-12-30 00:00:00', 'group_id' => null ) ) );
		$this->loginGuard->expects( self::never() )->method( 'unlockUser' );

		foreach ( array( 'нет', 'вычищен' ) as $label ) {
			try {
				$this->service->unlockLogins( 7, 501 );
				self::fail( "{$label}: ожидалось исключение" );
			} catch ( \InvalidArgumentException ) {
				$this->addToAssertionCount( 1 );
			}
		}
	}
}
