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
 * зашита внутри {@see EnrollmentService::enroll()} и намертво связана с потоком
 * заявок. Сначала сервис появился ради CSV-импорта с полным зачислением, затем
 * (аудит §2.2) на него переехал и сам `enroll()` — теперь это единственная
 * реализация провизии учёток.
 *
 * ### Ветки провизии (обе ветки provisionStudent/provisionParent)
 *
 * 1. У person уже есть `wpUserId` → переиспользовать, пароль не менять.
 * 2. Иначе email занят существующим WP-пользователем → привязать его к person, пароль не менять.
 * 3. Иначе создать нового WP-пользователя и привязать.
 *
 * Пароль ставится только при создании учётки: перезачисление (возврат заявки с данными
 * родителя) учётные данные не трогает. Исключение — явный пароль родителя
 * (перенос предмета между сайтами). `created = false` в результате — сигнал не слать письмо.
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
	 * @param string         $password Пароль новой учётки (у существующей не меняется)
	 *
	 * @throws \RuntimeException Коллизия логина/email при создании (wp_insert_user → WP_Error)
	 */
	public function provisionStudent( int $personId, PersonInputDTO $data, string $username, string $password ): AccountCredentialsDTO {
		$person = $this->personRepository->find( $personId );

		if ( null !== $person && null !== $person->wpUserId ) {
			return $this->existingCredentials( $person->wpUserId );
		}

		$email        = (string) ( $data->email ?? '' );
		$existingUser = '' !== $email ? $this->userManager->findByEmail( $email ) : null;

		if ( null !== $existingUser ) {
			$this->assertNotRole( $existingUser, UserRole::FSParent, 'Email ученика занят учётной записью родителя — укажите ученику другой email.' );

			$this->linkPerson( $personId, $existingUser->ID );
			return $this->existingCredentials( $existingUser->ID );
		}

		$userId = $this->createUser( $username, $email, $password, $data->studentAccountName(), $data->firstName, $data->lastName, UserRole::FSStudent );

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
	 * @param int                          $personId ID person родителя
	 * @param ParentDataDTO|PersonInputDTO $data     Данные родителя (email, ФИО)
	 * @param string|null                  $password Явный пароль; `null` — сгенерировать.
	 *                                               Задаётся при переносе предмета между
	 *                                               сайтами: пароль родителя приезжает в
	 *                                               пакете, и менять его при импорте нельзя —
	 *                                               иначе на новом сайте семья не войдёт по
	 *                                               прежним данным.
	 *
	 * @throws \RuntimeException Коллизия логина/email при создании
	 */
	public function provisionParent( int $personId, ParentDataDTO|PersonInputDTO $data, ?string $password = null ): AccountCredentialsDTO {
		$person = $this->personRepository->find( $personId );

		if ( null !== $person && null !== $person->wpUserId ) {
			return $this->existingCredentials( $person->wpUserId, $password );
		}

		$email        = (string) ( $data->email ?? '' );
		$existingUser = '' !== $email ? $this->userManager->findByEmail( $email ) : null;

		if ( null !== $existingUser ) {
			// Иначе родитель привязался бы к учётке ребёнка с тем же email и сменил ей пароль.
			$this->assertNotRole( $existingUser, UserRole::FSStudent, 'Email родителя занят учётной записью ученика — укажите родителю другой email.' );

			$this->linkPerson( $personId, $existingUser->ID );
			return $this->existingCredentials( $existingUser->ID, $password );
		}

		$login    = '' !== $email ? $email : ( 'parent_' . $personId );
		$password = ( null !== $password && '' !== $password ) ? $password : $this->passwordGenerator->generatePlain();
		$userId   = $this->createUser( $login, $email, $password, $data->fullName(), $data->firstName, $data->lastName, UserRole::FSParent );

		$this->logEvents->dispatch(
			LogEvent::UserCreated,
			new EntityChangedEvent( get_current_user_id(), OperationType::Create, EntityType::Parent, $personId, $data->fullName() )
		);
		$this->linkPerson( $personId, $userId );

		return new AccountCredentialsDTO( $userId, $login, $password, true );
	}

	/**
	 * Запрещает привязку физлица к учётке «противоположной» роли, найденной по email.
	 *
	 * Учителя, администраторы и пользователи без ролей плагина по-прежнему привязываются:
	 * преподаватель может быть и родителем ученика.
	 *
	 * @param \WP_User $user    Пользователь, найденный по email
	 * @param UserRole $role    Роль, привязка к которой запрещена
	 * @param string   $message Текст ошибки
	 *
	 * @throws \RuntimeException Пользователь с запрещённой ролью
	 */
	private function assertNotRole( \WP_User $user, UserRole $role, string $message ): void {
		if ( in_array( $role->value, (array) $user->roles, true ) ) {
			throw new \RuntimeException( $message );
		}
	}

	/**
	 * Данные уже существующей учётки. Пароль не меняется, кроме явно заданного вызывающим.
	 *
	 * @param int         $userId   ID WP-пользователя
	 * @param string|null $password Явный пароль (перенос между сайтами); `null`/пусто — оставить прежний
	 */
	private function existingCredentials( int $userId, ?string $password = null ): AccountCredentialsDTO {
		if ( null !== $password && '' !== $password ) {
			$this->passwordGenerator->setFromPlain( $userId, $password );
			$stored = array( 'login' => '', 'password' => $password );
		} else {
			$stored = $this->passwordGenerator->getCredentials( $userId ) ?? array();
		}

		$login = $this->userManager->find( $userId )?->user_login ?? (string) ( $stored['login'] ?? '' );

		return new AccountCredentialsDTO( $userId, $login, (string) ( $stored['password'] ?? '' ), false );
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
