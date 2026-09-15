<?php

declare(strict_types=1);

namespace Unit\Callbacks\Enrollment;

use Inc\Callbacks\Enrollment\ApplicationDataCallbacks;
use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Application\ApplicationDTO;
use Inc\Enums\Enrollment\ApplicationStatus;
use Inc\Managers\Person\UserManager;
use Inc\Repositories\WPDBRepositories\ApplicationRepository;
use Inc\Services\Security\CredentialsPolicy;
use Inc\Services\Security\PiiCryptoService;
use PHPUnit\Framework\TestCase;

/**
 * Правка заявки администратором не должна терять логин и пароль ученика:
 * раньше пересборка StudentDataDTO без них стирала учётные данные, и при зачислении
 * логином становился email.
 */
class ApplicationDataCallbacksTest extends TestCase {

	private PiiCryptoService $crypto;
	private ApplicationRepository $repository;
	private UserManager $users;
	private ApplicationDataCallbacks $cb;

	/** @var array<string, mixed>|null Данные, переданные в ApplicationRepository::update() */
	private ?array $saved = null;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_ajax();

		$this->crypto     = new PiiCryptoService();
		$this->repository = $this->createStub( ApplicationRepository::class );
		$this->users      = $this->createStub( UserManager::class );

		$this->repository->method( 'update' )->willReturnCallback( function ( int $id, array $data ): bool {
			$this->saved = $data;
			return true;
		} );

		$this->cb = new ApplicationDataCallbacks(
			$this->repository,
			$this->crypto,
			$this->createStub( LogEventDispatcherInterface::class ),
			new \Inc\Services\Application\LoginAvailabilityService( $this->users, $this->repository, $this->crypto ),
			new CredentialsPolicy(),
			new \Inc\Services\Enrollment\FamilyEmailPolicy(),
		);
	}

	protected function tearDown(): void {
		$_POST = array();
		parent::tearDown();
	}

	/** @param array<string, mixed> $studentData */
	private function givenApplication( array $studentData, ApplicationStatus $status = ApplicationStatus::PendingParent ): void {
		$app = new ApplicationDTO(
			id:                7,
			studentPersonId:   null,
			parentPersonId:    null,
			status:            $status,
			joinCodeHash:      null,
			joinCodeEnc:       null,
			joinCodeExpiresAt: null,
			studentEmailHash:  null,
			studentDataEnc:    $this->crypto->encrypt( (string) json_encode( $studentData ) ),
			parentDataEnc:     null,
			convertedRecordId: null,
			parentSubmittedIp: null,
			parentSubmittedUa: null,
			reviewedByUserId:  null,
			createdAt:         '2026-09-14 10:00:00',
			updatedAt:         '2026-09-14 10:00:00',
		);

		$this->repository->method( 'find' )->willReturn( $app );
	}

	/** @param array<string, string> $overrides */
	private function postEditForm( array $overrides = array() ): void {
		$_POST = array_merge(
			array(
				'application_id' => '7',
				'last_name'      => 'Иванов',
				'first_name'     => 'Иван',
				'middle_name'    => '',
				'email'          => 'ivan@mail.ru',
				'phone'          => '+79990000000',
				'school'         => 'Школа 1',
				'grade'          => '9',
				'birth_date'     => '2010-01-01',
				'login'          => 'ivan_2010',
				'password'       => 'Pass%12!',
			),
			$overrides
		);
	}

	/** @return array<string, mixed> */
	private function savedStudent(): array {
		self::assertNotNull( $this->saved, 'update() не вызывался' );
		return json_decode( $this->crypto->decrypt( (string) $this->saved['student_data_enc'] ), true );
	}

	private function studentBlob(): array {
		return array(
			'last_name'      => 'Иванов',
			'first_name'     => 'Иван',
			'email'          => 'ivan@mail.ru',
			'username'       => 'ivan_2010',
			'login_password' => 'Pass%12!',
		);
	}

	// ── ajaxUpdateApplicationData ─────────────────────────────────────────────────

	public function test_saving_other_fields_keeps_login_and_password(): void {
		$this->givenApplication( $this->studentBlob() );
		$this->postEditForm( array( 'school' => 'Лицей 2' ) );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxUpdateApplicationData() );

		self::assertTrue( $r->success );
		$student = $this->savedStudent();
		self::assertSame( 'ivan_2010', $student['username'] );
		self::assertSame( 'Pass%12!', $student['login_password'] );
		self::assertSame( 'Лицей 2', $student['school'] );
	}

	public function test_empty_login_and_password_keep_previous_values(): void {
		$this->givenApplication( $this->studentBlob() );
		$this->postEditForm( array( 'login' => '', 'password' => '' ) );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxUpdateApplicationData() );

		self::assertTrue( $r->success );
		self::assertSame( 'ivan_2010', $this->savedStudent()['username'] );
		self::assertSame( 'Pass%12!', $this->savedStudent()['login_password'] );
	}

	public function test_admin_changes_login_and_password(): void {
		$this->givenApplication( $this->studentBlob() );
		$this->users->method( 'findByLogin' )->willReturn( null );
		$this->postEditForm( array( 'login' => 'ivanov_new', 'password' => 'New№#@1' ) );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxUpdateApplicationData() );

		self::assertTrue( $r->success );
		self::assertSame( 'ivanov_new', $this->savedStudent()['username'] );
		self::assertSame( 'New№#@1', $this->savedStudent()['login_password'] );
		// Хэш логина обновлён — по нему другие заявки узнают, что логин занят.
		self::assertSame( $this->crypto->hash( 'ivanov_new' ), $this->saved['username_hash'] );
	}

	public function test_rejects_login_taken_by_another_application(): void {
		$this->givenApplication( $this->studentBlob() );
		$this->users->method( 'findByLogin' )->willReturn( null );
		$this->repository->method( 'existsActiveByUsernameHash' )->willReturn( true );
		$this->postEditForm( array( 'login' => 'petrov_2010' ) );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxUpdateApplicationData() );

		self::assertFalse( $r->success );
		self::assertNull( $this->saved );
	}

	public function test_rejects_taken_login(): void {
		$this->givenApplication( $this->studentBlob() );
		$this->users->method( 'findByLogin' )->willReturn( new \WP_User() );
		$this->postEditForm( array( 'login' => 'taken_login' ) );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxUpdateApplicationData() );

		self::assertFalse( $r->success );
		self::assertNull( $this->saved );
	}

	public function test_rejects_password_outside_allowed_set(): void {
		$this->givenApplication( $this->studentBlob() );
		$this->postEditForm( array( 'password' => 'bad pass' ) );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxUpdateApplicationData() );

		self::assertFalse( $r->success );
		self::assertSame( CredentialsPolicy::PASSWORD_ERROR, $r->payload );
		self::assertNull( $this->saved );
	}

	public function test_legacy_login_is_not_revalidated_when_unchanged(): void {
		// Старая заявка: логин не проходит нынешнее правило, но админ его не трогал.
		$this->givenApplication( array_merge( $this->studentBlob(), array( 'username' => 'old.login' ) ) );
		$this->postEditForm( array( 'login' => 'old.login' ) );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxUpdateApplicationData() );

		self::assertTrue( $r->success );
		self::assertSame( 'old.login', $this->savedStudent()['username'] );
	}

	// ── ajaxUpdateReviewData ──────────────────────────────────────────────────────

	public function test_review_update_rejects_parent_email_equal_to_student_email(): void {
		$this->givenApplication( $this->studentBlob(), ApplicationStatus::ReadyForReview );
		$_POST = array(
			'application_id'     => '7',
			'student_last_name'  => 'Иванов',
			'student_first_name' => 'Иван',
			'parent_last_name'   => 'Иванова',
			'parent_first_name'  => 'Мария',
			'parent_email'       => 'IVAN@mail.ru',
		);

		$r = fs_test_capture_json( fn() => $this->cb->ajaxUpdateReviewData() );

		self::assertFalse( $r->success );
		self::assertSame( \Inc\Services\Enrollment\FamilyEmailPolicy::MESSAGE, $r->payload );
		self::assertNull( $this->saved );
	}

	public function test_review_update_keeps_login_and_password(): void {
		$this->givenApplication( $this->studentBlob(), ApplicationStatus::ReadyForReview );
		$_POST = array(
			'application_id'      => '7',
			'student_last_name'   => 'Иванов',
			'student_first_name'  => 'Иван',
			'student_birth_date'  => '2010-01-01',
			'student_doc_type'    => 'passport',
			'student_doc_number'  => '1234 567890',
			'parent_last_name'    => 'Иванова',
			'parent_first_name'   => 'Мария',
		);

		$r = fs_test_capture_json( fn() => $this->cb->ajaxUpdateReviewData() );

		self::assertTrue( $r->success );
		self::assertSame( 'ivan_2010', $this->savedStudent()['username'] );
		self::assertSame( 'Pass%12!', $this->savedStudent()['login_password'] );
	}
}
