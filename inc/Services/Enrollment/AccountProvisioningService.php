<?php

declare( strict_types=1 );

namespace Inc\Services\Enrollment;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Import\AccountCredentialsDTO;
use Inc\DTO\Log\Events\EntityChangedEvent;
use Inc\DTO\Person\ParentDataDTO;
use Inc\DTO\Person\PersonInputDTO;
use Inc\DTO\Person\UserInputDTO;
use Inc\Enums\Access\UserRole;
use Inc\Enums\Log\EntityType;
use Inc\Enums\Log\LogEvent;
use Inc\Enums\Log\OperationType;
use Inc\Managers\Person\UserManager;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Services\Security\PasswordGeneratorService;

/**
 * Class AccountProvisioningService
 *
 * Создаёт или переиспользует WP-учётку для person (ученика/родителя).
 *
 * ### Зачем отдельный сервис
 *
 * Логика «найти существующую учётку по wpUserId/email → иначе создать» была
 * зашита внутри {@see \Inc\Services\Enrollment\EnrollmentService::enroll()} (строки 199–297)
 * и намертво связана с потоком заявок. Этот сервис — тот же паттерн, вынесенный
 * в переиспользуемый вид для CSV-импорта с полным зачислением.
 *
 * `enroll()` в этой итерации не трогается (экзамен-критичный поток под тестами);
 * его переезд на этот сервис — опциональный follow-up.
 *
 * ### Ветки провизии (обе ветки provisionStudent/provisionParent)
 *
 * 1. У person уже есть `wpUserId` → переиспользовать, установить новый пароль.
 * 2. Иначе email занят существующим WP-пользователем → привязать его к person.
 * 3. Иначе создать нового WP-пользователя и привязать.
 */
readonly class AccountProvisioningService {

	public function __construct(
		private UserManager                 $userManager,
		private PasswordGeneratorService    $passwordGenerator,
		private PersonRepository            $personRepository,
		private LogEventDispatcherInterface $logEvents,
	) {}

	/**
	 * Провизия учётки ученика. Логин и пароль заданы вызывающим кодом
	 * (в enrolled-импорте — обязательные колонки CSV, генерации нет).
	 *
	 * @param int            $personId ID person ученика
	 * @param PersonInputDTO $data     Данные ученика (email, ФИО)
	 * @param string         $username Желаемый логин (для новой учётки)
	 * @param string         $password Пароль (устанавливается в любой ветке)
	 *
	 * @throws \RuntimeException Коллизия логина/email при создании (wp_insert_user → WP_Error)
	 */
	public function provisionStudent( int $personId, PersonInputDTO $data, string $username, string $password ): AccountCredentialsDTO {
		$person = $this->personRepository->find( $personId );

		if ( null !== $person && null !== $person->wpUserId ) {
			$this->passwordGenerator->setFromPlain( $person->wpUserId, $password );
			$login = $this->userManager->find( $person->wpUserId )?->user_login ?? '';
			return new AccountCredentialsDTO( $person->wpUserId, $login, $password, false );
		}

		$email        = (string) ( $data->email ?? '' );
		$existingUser = '' !== $email ? $this->userManager->findByEmail( $email ) : null;

		if ( null !== $existingUser ) {
			$this->passwordGenerator->setFromPlain( $existingUser->ID, $password );
			$this->linkPerson( $personId, $existingUser->ID );
			return new AccountCredentialsDTO( $existingUser->ID, $existingUser->user_login, $password, false );
		}

		$userId = $this->createUser( $username, $email, $password, $data->fullName(), $data->firstName, $data->lastName, UserRole::FSStudent );

		$this->logEvents->dispatch(
			LogEvent::UserCreated,
			new EntityChangedEvent( get_current_user_id(), OperationType::Create, EntityType::Student, $personId, $data->fullName() )
		);
		$this->linkPerson( $personId, $userId );

		return new AccountCredentialsDTO( $userId, $username, $password, true );
	}

	/**
	 * Провизия учётки родителя. Логин = email, пароль генерируется.
	 *
	 * @param int                        $personId ID person родителя
	 * @param ParentDataDTO|PersonInputDTO $data   Данные родителя (email, ФИО)
	 *
	 * @throws \RuntimeException Коллизия логина/email при создании
	 */
	public function provisionParent( int $personId, ParentDataDTO|PersonInputDTO $data ): AccountCredentialsDTO {
		$person = $this->personRepository->find( $personId );

		if ( null !== $person && null !== $person->wpUserId ) {
			$password = $this->passwordGenerator->generateAndSet( $person->wpUserId );
			$login    = $this->userManager->find( $person->wpUserId )?->user_login ?? '';
			return new AccountCredentialsDTO( $person->wpUserId, $login, $password, false );
		}

		$email        = (string) ( $data->email ?? '' );
		$existingUser = '' !== $email ? $this->userManager->findByEmail( $email ) : null;

		if ( null !== $existingUser ) {
			$password = $this->passwordGenerator->generateAndSet( $existingUser->ID );
			$this->linkPerson( $personId, $existingUser->ID );
			return new AccountCredentialsDTO( $existingUser->ID, $existingUser->user_login, $password, false );
		}

		$login    = '' !== $email ? $email : ( 'parent_' . $personId );
		$password = $this->passwordGenerator->generatePlain();
		$userId   = $this->createUser( $login, $email, $password, $data->fullName(), $data->firstName, $data->lastName, UserRole::FSParent );

		$this->logEvents->dispatch(
			LogEvent::UserCreated,
			new EntityChangedEvent( get_current_user_id(), OperationType::Create, EntityType::Parent, $personId, $data->fullName() )
		);
		$this->linkPerson( $personId, $userId );

		return new AccountCredentialsDTO( $userId, $login, $password, true );
	}

	/**
	 * Создаёт WP-пользователя и сохраняет зашифрованную копию пароля.
	 *
	 * @throws \RuntimeException Пробрасывается из UserManager::create() при WP_Error (коллизия логина/email)
	 */
	private function createUser(
		string   $login,
		string   $email,
		string   $password,
		string   $displayName,
		string   $firstName,
		string   $lastName,
		UserRole $role
	): int {
		$userId = $this->userManager->create( new UserInputDTO(
			userLogin:   $login,
			userEmail:   $email,
			userPass:    $password,
			displayName: $displayName,
			firstName:   $firstName,
			lastName:    $lastName,
			role:        $role->value,
		) );

		$this->passwordGenerator->storeEncrypted( $userId, $password );

		return $userId;
	}

	/**
	 * Двусторонняя привязка person ↔ WP-пользователь.
	 */
	private function linkPerson( int $personId, int $userId ): void {
		$this->personRepository->setWpUser( $personId, $userId );
		$this->userManager->setPersonId( $userId, $personId );
	}
}
