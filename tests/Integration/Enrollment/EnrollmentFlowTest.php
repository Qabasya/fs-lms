<?php

declare(strict_types=1);

namespace Integration\Enrollment;

use FakeWpdb;
use Inc\Contracts\ClockInterface;
use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Application\ApplicationDTO;
use Inc\DTO\Enrollment\EnrollmentInputDTO;
use Inc\DTO\Enrollment\EnrollmentResultDTO;
use Inc\DTO\Person\PersonDTO;
use Inc\Enums\Enrollment\ApplicationStatus;
use Inc\Managers\Person\UserManager;
use Inc\Repositories\WPDBRepositories\ApplicationRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\PersonDocumentsRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Application\JoinCodeService;
use Inc\Services\ConsentService;
use Inc\Services\Email\EmailService;
use Inc\Services\Enrollment\EnrollmentService;
use Inc\Services\Person\PersonService;
use Inc\Services\Security\PasswordGeneratorService;
use Inc\Services\Security\PiiCryptoService;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end тест полного процесса зачисления (EnrollmentService::enroll()).
 *
 * Прогоняет весь конвейер на мок-инфраструктуре: дешифровка заявки →
 * дедупликация persons → создание student_record + consent в транзакции →
 * создание/переиспользование WP-аккаунтов → удаление заявки.
 * Проверяет разные сценарии: новый ученик, повторное зачисление отчисленного,
 * переиспользование существующих аккаунтов, авто-отправка писем,
 * откат транзакции, частичный сбой с переводом в recovery.
 */
class EnrollmentFlowTest extends TestCase {

	private ApplicationRepository $appRepo;
	private StudentRecordRepository $recordRepo;
	private PersonRepository $personRepo;
	private PersonDocumentsRepository $docsRepo;
	private PersonService $personService;
	private GroupsRepository $groupsRepo;
	private JoinCodeService $joinCodeService;
	private ConsentService $consentService;
	private UserManager $userManager;
	private PasswordGeneratorService $passwordGenerator;
	private EmailService $emailService;
	private PiiCryptoService $crypto;
	private ClockInterface $clock;
	private LogEventDispatcherInterface $logEvents;
	private EnrollmentService $service;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['wpdb'] = new \wpdb();

		$this->appRepo           = $this->createMock( ApplicationRepository::class );
		$this->recordRepo        = $this->createMock( StudentRecordRepository::class );
		$this->personRepo        = $this->createMock( PersonRepository::class );
		$this->docsRepo          = $this->createMock( PersonDocumentsRepository::class );
		$this->personService     = $this->createMock( PersonService::class );
		$this->groupsRepo        = $this->createMock( GroupsRepository::class );
		$this->joinCodeService   = $this->createMock( JoinCodeService::class );
		$this->consentService    = $this->createMock( ConsentService::class );
		$this->userManager       = $this->createMock( UserManager::class );
		$this->passwordGenerator = $this->createMock( PasswordGeneratorService::class );
		$this->emailService      = $this->createMock( EmailService::class );
		$this->crypto            = $this->createMock( PiiCryptoService::class );
		$this->clock             = $this->createMock( ClockInterface::class );
		$this->logEvents         = $this->createMock( LogEventDispatcherInterface::class );

		$this->clock->method( 'now' )->willReturn( '2024-01-01 12:00:00' );
		$this->crypto->method( 'encrypt' )->willReturn( 'enc_value' );
		$this->crypto->method( 'hash' )->willReturn( 'any_hash' );

		// Дешифровка заявки → JSON ученика/родителя по подстроке.
		$studentJson = (string) json_encode( array(
			'last_name'  => 'Иванов', 'first_name' => 'Иван',
			'doc_number' => '1234567890', 'email' => 'student@test.com', 'grade' => 10,
		) );
		$parentJson = (string) json_encode( array(
			'last_name'  => 'Петрова', 'first_name' => 'Мария',
			'doc_number' => '9876543210', 'email' => 'parent@test.com',
		) );
		$this->crypto->method( 'decrypt' )->willReturnCallback(
			static fn( string $enc ) => str_contains( $enc, 'student' ) ? $studentJson : $parentJson
		);

		$this->passwordGenerator->method( 'generatePlain' )->willReturn( 'PLAIN-PWD' );
		$this->passwordGenerator->method( 'generateAndSet' )->willReturn( 'GEN-PWD' );

		$this->service = new EnrollmentService(
			$this->appRepo,
			$this->recordRepo,
			$this->personRepo,
			$this->docsRepo,
			$this->personService,
			$this->groupsRepo,
			$this->joinCodeService,
			$this->consentService,
			$this->userManager,
			$this->passwordGenerator,
			$this->emailService,
			$this->crypto,
			$this->clock,
			$this->logEvents,
		);
	}

	// ── Helpers ──────────────────────────────────────────────────────────────────

	private function app( ?int $studentPersonId = null, ?int $parentPersonId = null ): ApplicationDTO {
		return new ApplicationDTO(
			id:                1,
			studentPersonId:   $studentPersonId,
			parentPersonId:    $parentPersonId,
			status:            ApplicationStatus::Enrolling,
			joinCodeHash:      null,
			joinCodeEnc:       null,
			joinCodeExpiresAt: null,
			studentEmailHash:  null,
			studentDataEnc:    'student_data_enc',
			parentDataEnc:     'parent_data_enc',
			convertedRecordId: null,
			parentSubmittedIp: null,
			parentSubmittedUa: null,
			reviewedByUserId:  null,
			createdAt:         '2024-01-01 00:00:00',
			updatedAt:         '2024-01-01 00:00:00',
		);
	}

	private function person( int $id, ?int $wpUserId = null, ?string $expelledAt = null ): PersonDTO {
		return new PersonDTO(
			id:         $id,
			wpUserId:   $wpUserId,
			lastName:   'Иванов',
			firstName:  'Иван',
			middleName: null,
			birthDate:  null,
			isStudent:  true,
			school:     null,
			grade:      null,
			expelledAt: $expelledAt,
			createdAt:  '2024-01-01 00:00:00',
			updatedAt:  '2024-01-01 00:00:00',
		);
	}

	private function input( bool $sendEmailAuto = false ): EnrollmentInputDTO {
		return new EnrollmentInputDTO(
			applicationId: 1,
			contractNo:    'К-001',
			contractDate:  '2024-01-01',
			orderNo:       'П-001',
			orderDate:     '2024-01-01',
			enrolledAt:    '2024-01-01',
			groupId:       5,
			sendEmailAuto: $sendEmailAuto,
		);
	}

	// ── Case 1: Полный happy path — новый ученик + новый родитель ─────────────────

	public function test_full_enrollment_creates_persons_records_users_and_deletes_application(): void {
		$this->appRepo->method( 'find' )->willReturn( $this->app() );
		$this->personService->method( 'findByDocNumberHash' )->willReturn( null );
		$this->userManager->method( 'findByEmail' )->willReturn( null );
		$this->recordRepo->method( 'existsActive' )->willReturn( false );

		// Новый ученик (100) и новый родитель (200) создаются дедуп-сервисом.
		$this->personService->expects( self::exactly( 2 ) )
			->method( 'createOrFindBy' )
			->willReturnOnConsecutiveCalls( 100, 200 );

		$this->recordRepo->method( 'create' )->willReturn( 555 );
		$this->consentService->expects( self::once() )->method( 'bindToPersons' )
			->with( 1, array( 'self' => 100, 'guardian' => 200 ) );

		$this->personRepo->method( 'find' )->willReturnCallback(
			fn( int $id ) => $this->person( $id, null )
		);

		// WP-аккаунты создаются для обоих.
		$this->userManager->expects( self::exactly( 2 ) )
			->method( 'create' )
			->willReturnOnConsecutiveCalls( 1001, 1002 );
		$this->personRepo->expects( self::exactly( 2 ) )->method( 'setWpUser' );

		// Заявка удаляется (не recovery).
		$this->appRepo->expects( self::once() )->method( 'forceDelete' )->with( 1 );
		$this->appRepo->expects( self::never() )->method( 'markConverted' );

		$result = $this->service->enroll( $this->input() );

		self::assertInstanceOf( EnrollmentResultDTO::class, $result );
		self::assertFalse( $result->partialFailure );
		self::assertSame( 555, $result->enrollmentId );
		self::assertSame( 1001, $result->studentUserId );
		self::assertSame( 1002, $result->guardianUserId );
		self::assertSame( 'student@test.com', $result->studentLogin );
		self::assertSame( 'parent@test.com', $result->guardianLogin );
	}

	// ── Case 2: Повторное зачисление отчисленного — без дубля person ───────────────

	public function test_reenrollment_of_expelled_student_clears_flag_and_reuses_person(): void {
		// Ученик 300 уже существует и отчислён; родитель 400 существует.
		$this->appRepo->method( 'find' )->willReturn( $this->app( 300, 400 ) );
		$this->personRepo->method( 'findIncludingDeleted' )
			->willReturn( $this->person( 300, 900, '2024-02-01 00:00:00' ) );
		$this->userManager->method( 'findByEmail' )->willReturn( null );
		$this->recordRepo->method( 'existsActive' )->willReturn( false );
		$this->recordRepo->method( 'create' )->willReturn( 556 );

		$this->personRepo->method( 'find' )->willReturnCallback(
			fn( int $id ) => $this->person( $id, 300 === $id ? 900 : 901 )
		);
		$this->userManager->method( 'find' )->willReturn( new \WP_User() );

		// Дубль person НЕ создаётся.
		$this->personService->expects( self::never() )->method( 'createOrFindBy' );
		// Флаг отчисления снимается.
		$this->personRepo->expects( self::once() )
			->method( 'update' )
			->with( 300, array( 'expelled_at' => null ) );
		// Аккаунты уже есть → новые не создаются.
		$this->userManager->expects( self::never() )->method( 'create' );

		$result = $this->service->enroll( $this->input() );

		self::assertFalse( $result->partialFailure );
		self::assertSame( 556, $result->enrollmentId );
		self::assertSame( 900, $result->studentUserId );
		self::assertSame( 901, $result->guardianUserId );
	}

	// ── Case 3: Авто-отправка писем обоим ─────────────────────────────────────────

	public function test_auto_email_sends_welcome_credentials_to_both(): void {
		$this->appRepo->method( 'find' )->willReturn( $this->app() );
		$this->personService->method( 'findByDocNumberHash' )->willReturn( null );
		$this->userManager->method( 'findByEmail' )->willReturn( null );
		$this->recordRepo->method( 'existsActive' )->willReturn( false );
		$this->personService->method( 'createOrFindBy' )->willReturnOnConsecutiveCalls( 100, 200 );
		$this->recordRepo->method( 'create' )->willReturn( 555 );
		$this->personRepo->method( 'find' )->willReturnCallback( fn( int $id ) => $this->person( $id, null ) );
		$this->userManager->method( 'create' )->willReturnOnConsecutiveCalls( 1001, 1002 );

		$this->emailService->expects( self::exactly( 2 ) )->method( 'sendWelcomeWithCredentials' );

		$result = $this->service->enroll( $this->input( sendEmailAuto: true ) );

		self::assertFalse( $result->partialFailure );
	}

	// ── Case 4: Откат транзакции при сбое создания записи ─────────────────────────

	public function test_transaction_rolls_back_when_record_creation_fails(): void {
		$fakeWpdb        = new FakeWpdb();
		$GLOBALS['wpdb'] = $fakeWpdb;

		$this->appRepo->method( 'find' )->willReturn( $this->app() );
		$this->personService->method( 'findByDocNumberHash' )->willReturn( null );
		$this->userManager->method( 'findByEmail' )->willReturn( null );
		$this->recordRepo->method( 'existsActive' )->willReturn( false );
		$this->personService->method( 'createOrFindBy' )->willReturnOnConsecutiveCalls( 100, 200 );

		// Создание student_record падает внутри транзакции.
		$this->recordRepo->method( 'create' )->willThrowException( new \RuntimeException( 'insert failed' ) );

		// Заявка НЕ удаляется и НЕ переводится в converted — всё откатывается.
		$this->appRepo->expects( self::never() )->method( 'forceDelete' );

		try {
			$this->service->enroll( $this->input() );
			self::fail( 'Ожидалось исключение из транзакции' );
		} catch ( \RuntimeException $e ) {
			self::assertSame( 'insert failed', $e->getMessage() );
		}

		self::assertContains( 'ROLLBACK', $fakeWpdb->queries, 'Транзакция должна быть откатана' );
		self::assertNotContains( 'COMMIT', $fakeWpdb->queries );
	}

	// ── Case 5: Частичный сбой создания WP-юзера → recovery ───────────────────────

	public function test_partial_failure_marks_application_converted_for_recovery(): void {
		$this->appRepo->method( 'find' )->willReturn( $this->app() );
		$this->personService->method( 'findByDocNumberHash' )->willReturn( null );
		$this->userManager->method( 'findByEmail' )->willReturn( null );
		$this->recordRepo->method( 'existsActive' )->willReturn( false );
		$this->personService->method( 'createOrFindBy' )->willReturnOnConsecutiveCalls( 100, 200 );
		$this->recordRepo->method( 'create' )->willReturn( 777 );
		$this->personRepo->method( 'find' )->willReturnCallback( fn( int $id ) => $this->person( $id, null ) );

		// Транзакция прошла, но создание WP-аккаунта падает.
		$this->userManager->method( 'create' )->willThrowException( new \RuntimeException( 'wp_insert_user failed' ) );

		// Заявка помечается converted (не удаляется) — её подберёт RecoveryService.
		$this->appRepo->expects( self::once() )->method( 'markConverted' )->with( 1, 777 );
		$this->appRepo->expects( self::never() )->method( 'forceDelete' );

		$result = $this->service->enroll( $this->input() );

		self::assertTrue( $result->partialFailure );
		self::assertSame( 777, $result->enrollmentId );
		self::assertSame( 0, $result->studentUserId );
		self::assertSame( 'wp_insert_user failed', $result->errorMessage );
	}

	// ── Case 6: Нельзя зачислить уже активного ученика ────────────────────────────

	public function test_enroll_rejects_student_already_active_in_group(): void {
		$this->appRepo->method( 'find' )->willReturn( $this->app( 300 ) );
		$this->personRepo->method( 'findIncludingDeleted' )->willReturn( $this->person( 300, 900 ) );
		$this->userManager->method( 'findByEmail' )->willReturn( null );
		$this->recordRepo->method( 'existsActive' )->willReturn( true );

		// До транзакции — запись не создаётся.
		$this->recordRepo->expects( self::never() )->method( 'create' );

		$this->expectException( \DomainException::class );
		$this->service->enroll( $this->input() );
	}
}
