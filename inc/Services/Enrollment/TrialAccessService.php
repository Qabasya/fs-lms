<?php

declare( strict_types=1 );

namespace Inc\Services\Enrollment;

use DomainException;
use InvalidArgumentException;
use Inc\Contracts\ClockInterface;
use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Application\ApplicationDTO;
use Inc\DTO\Enrollment\StudentDataDTO;
use Inc\DTO\Enrollment\StudentRecordInputDTO;
use Inc\DTO\Enrollment\TrialAccessResultDTO;
use Inc\DTO\Log\Events\EnrollmentStatusEvent;
use Inc\DTO\Person\PersonInputDTO;
use Inc\Enums\Enrollment\ApplicationStatus;
use Inc\Enums\Enrollment\EnrollmentStatus;
use Inc\Enums\Log\AuditAction;
use Inc\Enums\Log\LogEvent;
use Inc\Managers\Person\UserManager;
use Inc\Repositories\WPDBRepositories\ApplicationRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\PersonDocumentsRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Application\JoinCodeService;
use Inc\Services\Person\PersonService;
use Inc\Services\Security\PasswordGeneratorService;
use Inc\Services\Security\PiiCryptoService;
use Inc\Shared\PluginLogger;
use Inc\Shared\Traits\TransactionRunner;
use RuntimeException;

/**
 * Class TrialAccessService
 *
 * Временный доступ ученика к группе до зачисления — пока родитель не заполнил анкету
 * или сотрудник её не проверил.
 *
 * @package Inc\Services\Enrollment
 *
 * ### Как устроен
 *
 * Доступ — обычная активная запись `student_records` с `is_trial = 1`: без родителя
 * (`parent_person_id = 0`), договора и приказа. Поэтому кабинет, расписание, работы и
 * журнал видят ученика без доработок. Учётка — штатная учётка ученика с логином и паролем
 * из заявки; её же потом переиспользует зачисление ({@see EnrollmentTransaction} меняет
 * временную запись на настоящую).
 *
 * ### Сколько живёт
 *
 * Столько же, сколько заявка: доступ снимается, когда заявка истекла (20 дней без родителя)
 * или ушла в корзину — {@see self::revoke()} вызывают подписчики хуков
 * `fs_lms_application_expired` / `fs_lms_application_trashed`. Заполненная родителем заявка
 * не истекает, и доступ держится до зачисления.
 *
 * ### Что удаляется при снятии
 *
 * Сама запись — всегда. Физлицо и учётку — только если их завёл временный доступ
 * (`applications.trial_owner`): физлицо ученика из архива или учётку, найденную по email,
 * снятие не трогает.
 */
readonly class TrialAccessService {

	use TransactionRunner;

	public function __construct(
		private ApplicationRepository       $applications,
		private StudentRecordRepository     $records,
		private GroupsRepository            $groups,
		private PersonService               $people,
		private PersonRepository            $persons,
		private PersonDocumentsRepository   $personDocuments,
		private EnrollmentAccountsService   $accounts,
		private UserManager                 $users,
		private PasswordGeneratorService    $passwords,
		private PiiCryptoService            $crypto,
		private JoinCodeService             $joinCodes,
		private ClockInterface              $clock,
		private LogEventDispatcherInterface $logEvents,
	) {}

	/**
	 * Выдаёт временный доступ к группе: физлицо, запись и учётка ученика.
	 *
	 * @param int $applicationId ID заявки
	 * @param int $groupId       ID группы
	 *
	 * @throws InvalidArgumentException Заявка или группа не найдена
	 * @throws DomainException          Заявка не ждёт родителя/проверки, срок вышел, доступ уже выдан
	 * @throws RuntimeException         Учётку создать не удалось (выдача откатывается)
	 */
	public function grant( int $applicationId, int $groupId ): TrialAccessResultDTO {
		$app = $this->applications->find( $applicationId );
		if ( null === $app ) {
			throw new InvalidArgumentException( 'Заявка не найдена.' );
		}

		if ( ! in_array( $app->status, array( ApplicationStatus::PendingParent, ApplicationStatus::ReadyForReview ), true ) ) {
			throw new DomainException( 'Временный доступ выдаётся только по заявке, которая ждёт родителя или проверки.' );
		}

		if ( ApplicationStatus::PendingParent === $app->status && $this->joinCodes->isExpired( $app->expiresAt ) ) {
			throw new DomainException( 'Срок заявки истёк.' );
		}

		$group = $this->groups->findById( $groupId );
		if ( null === $group ) {
			throw new InvalidArgumentException( 'Группа не найдена.' );
		}

		if ( null !== $app->studentPersonId && array() !== $this->records->findTrialByStudent( $app->studentPersonId ) ) {
			throw new DomainException( 'Временный доступ по этой заявке уже выдан.' );
		}

		$student = $this->studentData( $app );

		[ $personId, $recordId, $owner ] = $this->inTransaction( function () use ( $app, $student, $groupId ): array {
			[ $personId, $owner ] = $this->resolvePerson( $app, $student );

			if ( $this->records->existsActive( $personId, $groupId ) ) {
				throw new DomainException( 'Ученик уже учится в этой группе.' );
			}

			$now      = $this->clock->now( 'mysql', true );
			$recordId = $this->records->create( new StudentRecordInputDTO(
				studentPersonId:    $personId,
				parentPersonId:     0,
				status:             EnrollmentStatus::Active->value,
				enrolledAt:         $now,
				createdAt:          $now,
				updatedAt:          $now,
				groupId:            $groupId,
				snapshotLastName:   $student->lastName,
				snapshotFirstName:  $student->firstName,
				snapshotMiddleName: $student->middleName ?: null,
				snapshotSchool:     $student->school ?: null,
				snapshotGrade:      $student->grade ? (string) $student->grade : null,
				enrolledByUserId:   get_current_user_id() ?: null,
				isTrial:            true,
			) );

			if ( 0 === $recordId ) {
				throw new RuntimeException( 'Не удалось создать запись временного доступа.' );
			}

			return array( $personId, $recordId, $owner );
		} );

		// Учётка — вне транзакции: wp_insert_user() не откатывается.
		try {
			$credentials = $this->accounts->provisionStudent( $student, $personId );
		} catch ( \Throwable $e ) {
			$this->revoke( $applicationId, get_current_user_id() );
			throw new RuntimeException( 'Не удалось создать учётку ученика: ' . $e->getMessage() );
		}

		// Учётку, найденную по email, заявка не создавала — и снимать её с доступом нельзя.
		if ( $owner && ! $credentials->created ) {
			$this->applications->update( $applicationId, array( 'trial_owner' => 0 ) );
		}

		$this->logEvents->dispatch(
			LogEvent::TrialAccessGranted,
			new EnrollmentStatusEvent( get_current_user_id(), AuditAction::GrantTrialAccess, $personId, $recordId, $groupId )
		);

		return new TrialAccessResultDTO(
			recordId:  $recordId,
			login:     $credentials->login,
			groupName: (string) $group->name,
			expiresAt: ApplicationStatus::PendingParent === $app->status ? (string) $app->expiresAt : '',
		);
	}

	/**
	 * Снимает временный доступ по заявке. Идемпотентно: без доступа — ничего не делает.
	 *
	 * @param int $applicationId ID заявки
	 * @param int $actorId       Кто снимает (0 — cron)
	 *
	 * @return bool Было ли что снимать
	 */
	public function revoke( int $applicationId, int $actorId ): bool {
		$app = $this->applications->find( $applicationId );
		if ( null === $app || null === $app->studentPersonId ) {
			return false;
		}

		$personId = $app->studentPersonId;
		$trials   = $this->records->findTrialByStudent( $personId );
		$wpUserId = $this->persons->findIncludingDeleted( $personId )?->wpUserId;

		$personDeleted = $this->inTransaction( function () use ( $app, $personId, $trials ): bool {
			$this->records->deleteTrialByStudent( $personId );

			if ( ! $app->trialOwner || $this->records->hasAnyRecord( $personId ) ) {
				// Чужое физлицо (ученик из архива) без активных записей — обратно в архив,
				// как после отчисления ({@see ExpulsionService::expel()}).
				if ( array() !== $trials && array() === $this->records->findActiveByStudent( $personId ) ) {
					$this->persons->softDelete( $personId );
				}
				return false;
			}

			// Физлицо, заведённое доступом, без единой записи уходит вместе с ним.

			$this->personDocuments->hardDeleteByPersonId( $personId );
			$this->persons->hardDelete( $personId );
			$this->applications->update( $app->id, array(
				'student_person_id' => null,
				'trial_owner'       => 0,
				'updated_at'        => $this->clock->now( 'mysql', true ),
			) );

			return true;
		} );

		if ( $personDeleted && $wpUserId ) {
			$this->users->delete( $wpUserId );
		}

		foreach ( $trials as $trial ) {
			$this->logEvents->dispatch(
				LogEvent::TrialAccessRevoked,
				new EnrollmentStatusEvent( $actorId, AuditAction::RevokeTrialAccess, $personId, $trial->id, $trial->groupId )
			);
		}

		return array() !== $trials || $personDeleted;
	}

	/**
	 * Переносит правку логина/пароля в заявке на учётку временного доступа —
	 * иначе ученик входил бы по старым данным до самого зачисления.
	 *
	 * Трогает только учётку, заведённую временным доступом: чужую учётку (из архива,
	 * найденную по email) правка заявки не меняет, её пароль ставит зачисление.
	 *
	 * @param int    $applicationId ID заявки
	 * @param string $login         Логин из заявки
	 * @param string $password      Пароль из заявки
	 */
	public function syncCredentials( int $applicationId, string $login, string $password ): void {
		$app = $this->applications->find( $applicationId );
		if ( null === $app || ! $app->trialOwner || null === $app->studentPersonId ) {
			return;
		}

		$wpUserId = $this->persons->find( $app->studentPersonId )?->wpUserId;
		if ( ! $wpUserId ) {
			return;
		}

		try {
			$user = $this->users->find( $wpUserId );
			if ( null !== $user && '' !== $login && $user->user_login !== $login ) {
				$this->users->changeLogin( $wpUserId, $login );
			}
			if ( '' !== $password ) {
				$this->passwords->setFromPlain( $wpUserId, $password );
			}
		} catch ( RuntimeException $e ) {
			PluginLogger::warning( 'TrialAccess', 'Учётка временного доступа не обновлена', array(
				'application_id' => $applicationId,
				'error'          => $e->getMessage(),
			) );
		}
	}

	/**
	 * Физлицо ученика для доступа и признак «заведено временным доступом».
	 *
	 * @param ApplicationDTO $app     Заявка
	 * @param StudentDataDTO $student Данные ученика
	 *
	 * @return array{0: int, 1: bool}
	 */
	private function resolvePerson( ApplicationDTO $app, StudentDataDTO $student ): array {
		if ( null !== $app->studentPersonId ) {
			// Ученик из архива помечен удалённым — иначе учётку не найти и кабинет не откроется.
			// Так же его «воскрешает» зачисление ({@see EnrollmentPersonResolver::resolveStudent()}).
			$existing = $this->persons->findIncludingDeleted( $app->studentPersonId );
			if ( null !== $existing && null !== $existing->expelledAt ) {
				$this->persons->update( $existing->id, array( 'expelled_at' => null ) );
			}

			return array( $app->studentPersonId, $app->trialOwner );
		}

		// До анкеты родителя номера документа нет, и createOrFindBy() заводит новое физлицо;
		// с номером (заявка уже проверяется) может найтись ученик из прошлых лет.
		$personId = $this->people->createOrFindBy( PersonInputDTO::fromStudentData( $student ) );
		$owner    = null === $this->persons->find( $personId )?->wpUserId && ! $this->records->hasAnyRecord( $personId );

		$this->applications->update( $app->id, array(
			'student_person_id' => $personId,
			'trial_owner'       => $owner ? 1 : 0,
			'updated_at'        => $this->clock->now( 'mysql', true ),
		) );

		return array( $personId, $owner );
	}

	/**
	 * @param ApplicationDTO $app Заявка
	 *
	 * @throws RuntimeException Данные ученика не расшифровались
	 */
	private function studentData( ApplicationDTO $app ): StudentDataDTO {
		try {
			return StudentDataDTO::fromArray(
				json_decode( $this->crypto->decrypt( (string) $app->studentDataEnc ), true ) ?? array()
			);
		} catch ( \Throwable $e ) {
			throw new RuntimeException( 'Не удалось расшифровать данные ученика.' );
		}
	}
}
