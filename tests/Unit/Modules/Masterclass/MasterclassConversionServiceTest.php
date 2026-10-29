<?php

declare( strict_types=1 );

namespace Unit\Modules\Masterclass;

use Inc\Contracts\ClockInterface;
use Inc\DTO\Application\ApplicationDTO;
use Inc\DTO\Person\PersonDTO;
use Inc\Managers\Person\UserManager;
use Inc\Modules\Masterclass\DTO\MasterclassAccountDTO;
use Inc\Modules\Masterclass\DTO\MasterclassDTO;
use Inc\Modules\Masterclass\Enums\MasterclassAccountState;
use Inc\Modules\Masterclass\Enums\MasterclassContactKind;
use Inc\Modules\Masterclass\Enums\MasterclassLogAction;
use Inc\Modules\Masterclass\Repositories\MasterclassAccountRepository;
use Inc\Modules\Masterclass\Repositories\MasterclassRepository;
use Inc\Modules\Masterclass\Services\MasterclassAccountService;
use Inc\Modules\Masterclass\Services\MasterclassConversionService;
use Inc\Modules\Masterclass\Services\MasterclassLogWriter;
use Inc\Repositories\WPDBRepositories\ApplicationRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Services\Enrollment\TrialAccessService;
use Inc\Services\Security\PiiCryptoService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Воронка: клик «Связаться» (отметка один раз, каждый клик в журнал), привязка заявки к аккаунту, зачисление
 * (имя учётки из физлица, запись в группе мастер-класса снята), предзаполнение формы заявки.
 */
class MasterclassConversionServiceTest extends TestCase {

	private const NOW = '2026-11-10 12:00:00';

	private MasterclassAccountRepository&MockObject $accounts;
	private MasterclassRepository&MockObject $masterclasses;
	private MasterclassAccountService&MockObject $accountService;
	private ApplicationRepository&MockObject $applications;
	private PersonRepository&MockObject $persons;
	private UserManager&MockObject $users;
	private TrialAccessService&MockObject $trial;
	private PiiCryptoService&MockObject $crypto;
	private MasterclassLogWriter&MockObject $log;
	private MasterclassConversionService $service;

	/** @var list<string> Порядок побочных действий. */
	private array $calls = array();

	/** @var list<array{0: MasterclassLogAction, 1: array<string, mixed>}> */
	private array $logged = array();

	protected function setUp(): void {
		$this->calls          = array();
		$this->logged         = array();
		$this->accounts       = $this->createMock( MasterclassAccountRepository::class );
		$this->masterclasses  = $this->createMock( MasterclassRepository::class );
		$this->accountService = $this->createMock( MasterclassAccountService::class );
		$this->applications   = $this->createMock( ApplicationRepository::class );
		$this->persons        = $this->createMock( PersonRepository::class );
		$this->users          = $this->createMock( UserManager::class );
		$this->trial          = $this->createMock( TrialAccessService::class );
		$this->crypto         = $this->createMock( PiiCryptoService::class );
		$this->log            = $this->createMock( MasterclassLogWriter::class );

		$this->log->method( 'write' )->willReturnCallback(
			function ( MasterclassLogAction $action, array $data = array() ): void {
				$this->calls[]  = 'log:' . $action->value;
				$this->logged[] = array( $action, $data );
			}
		);

		$clock = $this->createStub( ClockInterface::class );
		$clock->method( 'now' )->willReturn( self::NOW );

		$this->service = new MasterclassConversionService(
			$this->accounts,
			$this->masterclasses,
			$this->accountService,
			$this->applications,
			$this->persons,
			$this->users,
			$this->trial,
			$this->crypto,
			$this->log,
			$clock
		);
	}

	private function account( array $over = array() ): MasterclassAccountDTO {
		return MasterclassAccountDTO::fromArray( array_merge(
			array( 'id' => 5, 'masterclass_id' => 7, 'user_id' => 105, 'person_id' => 205, 'student_record_id' => 305, 'state' => 'active' ),
			$over
		) );
	}

	private function application( array $over = array() ): ApplicationDTO {
		return ApplicationDTO::fromArray( array_merge(
			array( 'id' => 90, 'status' => 'pending_parent', 'student_data_enc' => 'ENC', 'created_at' => self::NOW, 'updated_at' => self::NOW ),
			$over
		) );
	}

	private function person( array $over = array() ): PersonDTO {
		return PersonDTO::fromArray( array_merge(
			array( 'id' => 205, 'wp_user_id' => 105, 'last_name' => 'Иванов', 'first_name' => 'Пётр', 'is_student' => 1, 'created_at' => self::NOW, 'updated_at' => self::NOW ),
			$over
		) );
	}

	/** Настраивает успешную привязку: живой аккаунт, свободная заявка, расшифровка с логином и паролем. */
	private function givenBindable( array $accountOver = array() ): void {
		$this->accounts->method( 'findByUserId' )->with( 105 )->willReturn( $this->account( $accountOver ) );
		$this->accountService->method( 'hasLiveApplication' )->willReturn( false );
		$this->applications->method( 'find' )->with( 90 )->willReturn( $this->application() );
		$this->crypto->method( 'decrypt' )->with( 'ENC' )->willReturn( json_encode( array( 'username' => 'ivanov_p', 'login_password' => 'Secret123' ) ) );
	}

	/** @return list<string> */
	private function loggedActions(): array {
		return array_map( static fn ( array $l ): string => $l[0]->value, $this->logged );
	}

	// ── logContact ──────────────────────────────────────────────────────

	#[DataProvider( 'kindProvider' )]
	public function test_every_click_is_marked_and_logged( MasterclassContactKind $kind, MasterclassLogAction $action ): void {
		$this->accounts->method( 'findByUserId' )->with( 105 )->willReturn( $this->account() );
		$this->accounts->expects( self::once() )->method( 'markContact' )->with( 5, self::NOW )->willReturn( true );

		$this->service->logContact( 105, $kind );

		self::assertSame( array( $action->value ), $this->loggedActions() );
		self::assertSame( 5, $this->logged[0][1]['account_id'] );
		self::assertSame( 7, $this->logged[0][1]['masterclass_id'] );
		self::assertSame( 105, $this->logged[0][1]['actor'] );
	}

	public static function kindProvider(): array {
		return array(
			'окно'       => array( MasterclassContactKind::Open, MasterclassLogAction::ContactOpened ),
			'телефон'    => array( MasterclassContactKind::Phone, MasterclassLogAction::ContactPhone ),
			'мессенджер' => array( MasterclassContactKind::Messenger, MasterclassLogAction::ContactMessenger ),
			'заявка'     => array( MasterclassContactKind::Apply, MasterclassLogAction::ApplyClicked ),
		);
	}

	public function test_a_repeat_click_is_still_logged_but_marks_nothing_new(): void {
		$this->accounts->method( 'findByUserId' )->willReturn( $this->account( array( 'contact_at' => '2026-11-10 11:00:00' ) ) );
		$this->accounts->method( 'markContact' )->willReturn( false );

		$this->service->logContact( 105, MasterclassContactKind::Phone );

		self::assertSame( array( 'contact_phone' ), $this->loggedActions() );
	}

	public function test_a_user_without_an_account_is_ignored_on_contact(): void {
		$this->accounts->method( 'findByUserId' )->willReturn( null );
		$this->accounts->expects( self::never() )->method( 'markContact' );

		$this->service->logContact( 7, MasterclassContactKind::Open );

		self::assertSame( array(), $this->loggedActions() );
	}

	#[DataProvider( 'notLiveProvider' )]
	public function test_deleted_and_enrolled_accounts_are_ignored_on_contact( array $over ): void {
		$this->accounts->method( 'findByUserId' )->willReturn( $this->account( $over ) );
		$this->accounts->expects( self::never() )->method( 'markContact' );

		$this->service->logContact( 105, MasterclassContactKind::Open );

		self::assertSame( array(), $this->loggedActions() );
	}

	public static function notLiveProvider(): array {
		return array(
			'удалён'   => array( array( 'deleted_at' => '2026-11-09 00:00:00' ) ),
			'зачислен' => array( array( 'state' => 'enrolled' ) ),
		);
	}

	// ── bindApplication ─────────────────────────────────────────────────

	public function test_bind_links_the_application_to_the_account_and_moves_the_credentials(): void {
		$this->givenBindable();

		$this->applications->expects( self::once() )->method( 'update' )->with(
			90,
			array( 'student_person_id' => 205, 'trial_owner' => 1, 'updated_at' => self::NOW )
		)->willReturnCallback( function (): bool {
			$this->calls[] = 'update';
			return true;
		} );
		$this->accounts->expects( self::once() )->method( 'setState' )->with( 5, MasterclassAccountState::Applied, 90 )->willReturnCallback(
			function (): void { $this->calls[] = 'state'; }
		);
		$this->trial->expects( self::once() )->method( 'syncCredentials' )->with( 90, 'ivanov_p', 'Secret123' )->willReturnCallback(
			function (): void { $this->calls[] = 'sync'; }
		);

		self::assertTrue( $this->service->bindApplication( 90, 105 ) );
		self::assertSame( array( 'update', 'state', 'sync', 'log:application_bound' ), $this->calls );
	}

	public function test_bind_never_writes_the_password_into_the_journal(): void {
		$this->givenBindable();
		$this->applications->method( 'update' )->willReturn( true );

		$this->service->bindApplication( 90, 105 );

		self::assertStringNotContainsString( 'Secret123', json_encode( $this->logged ) );
		self::assertSame( 5, $this->logged[0][1]['account_id'] );
		self::assertSame( 90, $this->logged[0][1]['context']['application_id'] );
	}

	public function test_a_user_without_an_account_is_not_touched_and_not_logged(): void {
		$this->accounts->method( 'findByUserId' )->willReturn( null );
		$this->applications->expects( self::never() )->method( 'find' );
		$this->applications->expects( self::never() )->method( 'update' );

		self::assertFalse( $this->service->bindApplication( 90, 7 ) );
		self::assertSame( array(), $this->loggedActions(), 'обычный пользователь подаёт заявки постоянно — журнал не засоряется' );
	}

	#[DataProvider( 'notLiveProvider' )]
	public function test_deleted_and_enrolled_accounts_do_not_bind( array $over ): void {
		$this->accounts->method( 'findByUserId' )->willReturn( $this->account( $over ) );
		$this->applications->expects( self::never() )->method( 'update' );

		self::assertFalse( $this->service->bindApplication( 90, 105 ) );
	}

	public function test_an_account_with_a_live_application_does_not_bind_another(): void {
		$this->accounts->method( 'findByUserId' )->willReturn( $this->account( array( 'state' => 'applied', 'application_id' => 80 ) ) );
		$this->accountService->method( 'hasLiveApplication' )->willReturn( true );
		$this->applications->expects( self::never() )->method( 'update' );

		self::assertFalse( $this->service->bindApplication( 90, 105 ) );
		self::assertSame( array( 'application_bind_failed' ), $this->loggedActions() );
	}

	public function test_an_account_whose_previous_application_is_over_may_bind_a_new_one(): void {
		$this->givenBindable( array( 'state' => 'applied', 'application_id' => 80 ) );
		$this->applications->method( 'update' )->willReturn( true );

		self::assertTrue( $this->service->bindApplication( 90, 105 ) );
	}

	public function test_a_missing_application_does_not_bind(): void {
		$this->accounts->method( 'findByUserId' )->willReturn( $this->account() );
		$this->accountService->method( 'hasLiveApplication' )->willReturn( false );
		$this->applications->method( 'find' )->willReturn( null );
		$this->applications->expects( self::never() )->method( 'update' );

		self::assertFalse( $this->service->bindApplication( 90, 105 ) );
		self::assertSame( array( 'application_bind_failed' ), $this->loggedActions() );
	}

	public function test_an_application_already_tied_to_a_person_is_left_alone(): void {
		$this->accounts->method( 'findByUserId' )->willReturn( $this->account() );
		$this->accountService->method( 'hasLiveApplication' )->willReturn( false );
		$this->applications->method( 'find' )->willReturn( $this->application( array( 'student_person_id' => 999 ) ) );
		$this->applications->expects( self::never() )->method( 'update' );

		self::assertFalse( $this->service->bindApplication( 90, 105 ) );
	}

	public function test_a_failure_inside_never_leaves_the_service_and_is_logged(): void {
		$this->accounts->method( 'findByUserId' )->willReturn( $this->account() );
		$this->accountService->method( 'hasLiveApplication' )->willReturn( false );
		$this->applications->method( 'find' )->willReturn( $this->application() );
		$this->crypto->method( 'decrypt' )->willThrowException( new \RuntimeException( 'decrypt failed' ) );
		$this->applications->expects( self::never() )->method( 'update' );

		self::assertFalse( $this->service->bindApplication( 90, 105 ) );
		self::assertSame( array( 'application_bind_failed' ), $this->loggedActions() );
	}

	public function test_a_failed_application_update_is_a_failure_and_changes_nothing_else(): void {
		$this->givenBindable();
		$this->applications->method( 'update' )->willReturn( false );
		$this->accounts->expects( self::never() )->method( 'setState' );
		$this->trial->expects( self::never() )->method( 'syncCredentials' );

		self::assertFalse( $this->service->bindApplication( 90, 105 ) );
		self::assertSame( array( 'application_bind_failed' ), $this->loggedActions() );
	}

	public function test_a_failure_after_the_update_is_swallowed_too(): void {
		$this->givenBindable();
		$this->applications->method( 'update' )->willReturn( true );
		$this->trial->method( 'syncCredentials' )->willThrowException( new \RuntimeException( 'boom' ) );

		self::assertFalse( $this->service->bindApplication( 90, 105 ) );
		self::assertSame( array( 'application_bind_failed' ), $this->loggedActions() );
	}

	// ── markEnrolled ────────────────────────────────────────────────────

	public function test_enrollment_marks_the_account_renames_the_user_and_frees_the_group_record(): void {
		$this->accounts->method( 'findByPersonId' )->with( 205 )->willReturn( $this->account( array( 'state' => 'applied', 'application_id' => 90 ) ) );
		$this->persons->method( 'find' )->with( 205 )->willReturn( $this->person() );
		$this->accounts->expects( self::once() )->method( 'setState' )->with( 5, MasterclassAccountState::Enrolled )->willReturnCallback(
			function (): void { $this->calls[] = 'state'; }
		);
		$this->accounts->expects( self::once() )->method( 'clearStudentRecord' )->with( 5 )->willReturnCallback(
			function (): void { $this->calls[] = 'detach'; }
		);
		$this->users->expects( self::once() )->method( 'update' )->with(
			105,
			array( 'display_name' => 'Иванов Пётр', 'first_name' => 'Пётр', 'last_name' => 'Иванов' )
		)->willReturnCallback( function (): bool {
			$this->calls[] = 'rename';
			return true;
		} );

		self::assertTrue( $this->service->markEnrolled( 205 ) );
		self::assertSame( array( 'state', 'detach', 'rename', 'log:enrolled' ), $this->calls );
		self::assertSame( 5, $this->logged[0][1]['account_id'] );
	}

	public function test_enrollment_of_a_person_without_an_account_does_nothing(): void {
		$this->accounts->method( 'findByPersonId' )->willReturn( null );
		$this->accounts->expects( self::never() )->method( 'setState' );
		$this->users->expects( self::never() )->method( 'update' );

		self::assertFalse( $this->service->markEnrolled( 777 ) );
		self::assertSame( array(), $this->loggedActions() );
	}

	#[DataProvider( 'notLiveProvider' )]
	public function test_enrollment_of_a_deleted_or_already_enrolled_account_does_nothing( array $over ): void {
		$this->accounts->method( 'findByPersonId' )->willReturn( $this->account( $over ) );
		$this->accounts->expects( self::never() )->method( 'setState' );
		$this->users->expects( self::never() )->method( 'update' );

		self::assertFalse( $this->service->markEnrolled( 205 ) );
	}

	public function test_enrollment_without_the_person_still_marks_the_account_but_keeps_the_name(): void {
		$this->accounts->method( 'findByPersonId' )->willReturn( $this->account() );
		$this->persons->method( 'find' )->willReturn( null );
		$this->accounts->expects( self::once() )->method( 'setState' )->with( 5, MasterclassAccountState::Enrolled );
		$this->users->expects( self::never() )->method( 'update' );

		self::assertTrue( $this->service->markEnrolled( 205 ) );
	}

	// ── prefill ─────────────────────────────────────────────────────────

	public function test_prefill_gives_the_subject_and_the_school_of_the_masterclass(): void {
		$this->accounts->method( 'findByUserId' )->willReturn( $this->account() );
		$this->masterclasses->method( 'find' )->with( 7 )->willReturn( MasterclassDTO::fromArray( array( 'id' => 7, 'school_name' => 'Школа №5', 'subject_key' => 'inf_ege' ) ) );

		self::assertSame( array( 'subject_key' => 'inf_ege', 'school' => 'Школа №5' ), $this->service->prefill( 105 ) );
	}

	public function test_prefill_is_empty_for_anyone_else(): void {
		$this->accounts->method( 'findByUserId' )->willReturn( null );

		self::assertSame( array(), $this->service->prefill( 7 ) );
	}

	#[DataProvider( 'notLiveProvider' )]
	public function test_prefill_is_empty_for_deleted_and_enrolled_accounts( array $over ): void {
		$this->accounts->method( 'findByUserId' )->willReturn( $this->account( $over ) );
		$this->masterclasses->expects( self::never() )->method( 'find' );

		self::assertSame( array(), $this->service->prefill( 105 ) );
	}
}
