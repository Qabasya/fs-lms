<?php

declare( strict_types=1 );

namespace Unit\Services\Enrollment;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Person\ParentDataDTO;
use Inc\DTO\Person\PersonDTO;
use Inc\DTO\Person\PersonInputDTO;
use Inc\Enums\Log\LogEvent;
use Inc\Managers\Person\UserManager;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Services\Enrollment\AccountProvisioningService;
use Inc\Services\Security\PasswordGeneratorService;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use WP_User;

class AccountProvisioningServiceTest extends TestCase {

	private UserManager $userManager;
	private PasswordGeneratorService $passwordGenerator;
	private PersonRepository $personRepository;
	private LogEventDispatcherInterface $logEvents;
	private AccountProvisioningService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->userManager       = $this->createMock( UserManager::class );
		$this->passwordGenerator = $this->createMock( PasswordGeneratorService::class );
		$this->personRepository  = $this->createMock( PersonRepository::class );
		$this->logEvents         = $this->createMock( LogEventDispatcherInterface::class );

		$this->service = new AccountProvisioningService(
			$this->userManager,
			$this->passwordGenerator,
			$this->personRepository,
			$this->logEvents,
		);
	}

	private function studentInput( ?string $email = 'ivan@example.com' ): PersonInputDTO {
		return new PersonInputDTO(
			lastName:  'Иванов',
			firstName: 'Иван',
			docNumber: '',
			isStudent: true,
			email:     $email,
		);
	}

	private function parentInput( ?string $email = 'maria@example.com' ): PersonInputDTO {
		return new PersonInputDTO(
			lastName:  'Иванова',
			firstName: 'Мария',
			docNumber: '',
			isStudent: false,
			email:     $email,
		);
	}

	private function makePerson( int $id, ?int $wpUserId ): PersonDTO {
		return new PersonDTO(
			id:         $id,
			wpUserId:   $wpUserId,
			lastName:   'Тест',
			firstName:  'Тест',
			middleName: null,
			birthDate:  null,
			isStudent:  true,
			school:     null,
			grade:      null,
			expelledAt: null,
			createdAt:  '2024-01-01 00:00:00',
			updatedAt:  '2024-01-01 00:00:00',
		);
	}

	// ── provisionStudent ───────────────────────────────────────────────────

	public function test_provision_student_creates_new_account_with_given_credentials(): void {
		$this->personRepository->method( 'find' )->willReturn( $this->makePerson( 10, null ) );
		$this->userManager->method( 'findByEmail' )->willReturn( null );
		$this->userManager->method( 'create' )->willReturn( 501 );

		$this->userManager->expects( $this->once() )->method( 'create' );
		$this->passwordGenerator->expects( $this->once() )->method( 'storeEncrypted' )->with( 501, 'Passw0rd!' );
		$this->personRepository->expects( $this->once() )->method( 'setWpUser' )->with( 10, 501 );
		$this->userManager->expects( $this->once() )->method( 'setPersonId' )->with( 501, 10 );
		$this->logEvents->expects( $this->once() )->method( 'dispatch' )->with( LogEvent::UserCreated, $this->anything() );

		$result = $this->service->provisionStudent( 10, $this->studentInput(), 'ivanov', 'Passw0rd!' );

		$this->assertSame( 501, $result->userId );
		$this->assertSame( 'ivanov', $result->login );
		$this->assertSame( 'Passw0rd!', $result->password );
		$this->assertTrue( $result->created );
	}

	public function test_provision_student_reuses_account_when_person_already_linked(): void {
		$this->personRepository->method( 'find' )->willReturn( $this->makePerson( 10, 501 ) );

		$existing = new WP_User();
		$existing->ID         = 501;
		$existing->user_login = 'ivanov';
		$this->userManager->method( 'find' )->willReturn( $existing );

		$this->userManager->expects( $this->never() )->method( 'create' );
		$this->passwordGenerator->expects( $this->once() )->method( 'setFromPlain' )->with( 501, 'NewPass1' );
		$this->logEvents->expects( $this->never() )->method( 'dispatch' );

		$result = $this->service->provisionStudent( 10, $this->studentInput(), 'ivanov', 'NewPass1' );

		$this->assertSame( 501, $result->userId );
		$this->assertSame( 'ivanov', $result->login );
		$this->assertFalse( $result->created );
	}

	public function test_provision_student_links_existing_user_found_by_email(): void {
		$this->personRepository->method( 'find' )->willReturn( $this->makePerson( 10, null ) );

		$existing = new WP_User();
		$existing->ID         = 777;
		$existing->user_login = 'old_login';
		$this->userManager->method( 'findByEmail' )->with( 'ivan@example.com' )->willReturn( $existing );

		$this->userManager->expects( $this->never() )->method( 'create' );
		$this->passwordGenerator->expects( $this->once() )->method( 'setFromPlain' )->with( 777, 'Passw0rd!' );
		$this->personRepository->expects( $this->once() )->method( 'setWpUser' )->with( 10, 777 );

		$result = $this->service->provisionStudent( 10, $this->studentInput(), 'ivanov', 'Passw0rd!' );

		$this->assertSame( 777, $result->userId );
		$this->assertSame( 'old_login', $result->login );
		$this->assertFalse( $result->created );
	}

	public function test_provision_student_throws_on_login_collision(): void {
		$this->personRepository->method( 'find' )->willReturn( $this->makePerson( 10, null ) );
		$this->userManager->method( 'findByEmail' )->willReturn( null );
		$this->userManager->method( 'create' )->willThrowException( new RuntimeException( 'Логин уже занят.' ) );

		$this->expectException( RuntimeException::class );

		$this->service->provisionStudent( 10, $this->studentInput(), 'ivanov', 'Passw0rd!' );
	}

	// ── provisionParent ────────────────────────────────────────────────────

	public function test_provision_parent_creates_new_account_with_generated_password(): void {
		$this->personRepository->method( 'find' )->willReturn( $this->makePerson( 20, null ) );
		$this->userManager->method( 'findByEmail' )->willReturn( null );
		$this->userManager->method( 'create' )->willReturn( 601 );
		$this->passwordGenerator->method( 'generatePlain' )->willReturn( 'GenPass9' );

		$this->personRepository->expects( $this->once() )->method( 'setWpUser' )->with( 20, 601 );
		$this->logEvents->expects( $this->once() )->method( 'dispatch' )->with( LogEvent::UserCreated, $this->anything() );

		$result = $this->service->provisionParent( 20, $this->parentInput() );

		$this->assertSame( 601, $result->userId );
		$this->assertSame( 'maria@example.com', $result->login );
		$this->assertSame( 'GenPass9', $result->password );
		$this->assertTrue( $result->created );
	}

	public function test_provision_parent_falls_back_to_generated_login_when_email_empty(): void {
		$this->personRepository->method( 'find' )->willReturn( $this->makePerson( 20, null ) );
		$this->userManager->method( 'findByEmail' )->willReturn( null );
		$this->userManager->method( 'create' )->willReturn( 602 );
		$this->passwordGenerator->method( 'generatePlain' )->willReturn( 'GenPass9' );

		$result = $this->service->provisionParent( 20, $this->parentInput( email: '' ) );

		$this->assertSame( 'parent_20', $result->login );
	}

	public function test_provision_parent_reuses_account_when_person_already_linked(): void {
		$this->personRepository->method( 'find' )->willReturn( $this->makePerson( 20, 601 ) );

		$existing = new WP_User();
		$existing->ID         = 601;
		$existing->user_login = 'maria@example.com';
		$this->userManager->method( 'find' )->willReturn( $existing );
		$this->passwordGenerator->method( 'generateAndSet' )->with( 601 )->willReturn( 'RotatedPass' );

		$this->userManager->expects( $this->never() )->method( 'create' );

		$result = $this->service->provisionParent( 20, $this->parentInput() );

		$this->assertSame( 601, $result->userId );
		$this->assertSame( 'RotatedPass', $result->password );
		$this->assertFalse( $result->created );
	}

	public function test_provision_parent_accepts_parent_data_dto(): void {
		$this->personRepository->method( 'find' )->willReturn( $this->makePerson( 20, null ) );
		$this->userManager->method( 'findByEmail' )->willReturn( null );
		$this->userManager->method( 'create' )->willReturn( 603 );
		$this->passwordGenerator->method( 'generatePlain' )->willReturn( 'GenPass9' );

		$parentDto = ParentDataDTO::fromArray( array(
			'last_name'  => 'Петрова',
			'first_name' => 'Ольга',
			'email'      => 'olga@example.com',
		) );

		$result = $this->service->provisionParent( 20, $parentDto );

		$this->assertSame( 'olga@example.com', $result->login );
		$this->assertTrue( $result->created );
	}
}
