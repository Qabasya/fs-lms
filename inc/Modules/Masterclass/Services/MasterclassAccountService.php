<?php

declare( strict_types=1 );

namespace Inc\Modules\Masterclass\Services;

use Inc\Contracts\ClockInterface;
use Inc\DTO\Enrollment\StudentRecordInputDTO;
use Inc\DTO\Person\PersonInputDTO;
use Inc\Enums\Enrollment\ApplicationStatus;
use Inc\Enums\Enrollment\EnrollmentStatus;
use Inc\Enums\Wp\PageRoutes;
use Inc\Managers\Person\UserManager;
use Inc\Modules\Masterclass\DTO\MasterclassAccountDTO;
use Inc\Modules\Masterclass\DTO\MasterclassDTO;
use Inc\Modules\Masterclass\DTO\MasterclassFlyerDTO;
use Inc\Modules\Masterclass\Enums\AccountDeleteReason;
use Inc\Modules\Masterclass\Enums\MasterclassAccountState;
use Inc\Modules\Masterclass\Enums\MasterclassLogAction;
use Inc\Modules\Masterclass\Repositories\MasterclassAccountRepository;
use Inc\Modules\Masterclass\Repositories\MasterclassRepository;
use Inc\Repositories\WPDBRepositories\ApplicationRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Deletion\DeleteStudentEvent;
use Inc\Services\Deletion\DeletionEventDispatcher;
use Inc\Services\Enrollment\AccountProvisioningService;
use Inc\Services\Exam\ExamTime;
use Inc\Services\Person\PersonService;
use Inc\Services\Security\LoginGuardService;
use Inc\Services\Security\PasswordGeneratorService;
use Inc\Shared\PluginLogger;

/**
 * Class MasterclassAccountService
 *
 * Временные аккаунты мастер-класса: выпуск порциями, флаеры, список для страницы, удаление и отвязка,
 * снятие блокировок входа класса.
 *
 * ### Аккаунт
 *
 * Номер `N` — это `id` строки `masterclass_accounts` (строки не удаляются, номер не повторяется). Логин `student-N`
 * строит только {@see self::loginFor()}; физлицо — «Ученик#N»; запись — временная (`is_trial`) в служебной группе
 * мастер-класса. Учётка создаётся ядром (`AccountProvisioningService`), она же сохраняет зашифрованную копию пароля
 * для флаера.
 *
 * ### Сбой на середине
 *
 * `wp_insert_user()` не откатывается транзакцией, поэтому аккаунт собирается по шагам, а сбой любого шага снимает
 * то, что успело появиться (физлицо с записью и учёткой — штатным событием удаления ученика), помечает строку
 * `create_failed` и отдаёт порцию дальше: один плохой номер не срывает остальные 24.
 *
 * @package Inc\Modules\Masterclass\Services
 */
class MasterclassAccountService {

	/** Префикс логина: `student-N`. */
	public const LOGIN_PREFIX = 'student-';

	/** Предел одного вызова `createChunk()` (хеш пароля ~50 мс + 5–6 вставок на аккаунт). */
	public const CHUNK_MAX = 25;

	/** Префикс имени физлица и учётки: «Ученик#N». */
	private const NAME_PREFIX = 'Ученик#';

	public function __construct(
		private readonly MasterclassAccountRepository $accounts,
		private readonly MasterclassRepository $masterclasses,
		private readonly AccountProvisioningService $provisioning,
		private readonly PersonService $persons,
		private readonly StudentRecordRepository $records,
		private readonly UserManager $users,
		private readonly PasswordGeneratorService $passwords,
		private readonly DeletionEventDispatcher $deletion,
		private readonly ApplicationRepository $applications,
		private readonly MasterclassLogWriter $log,
		private readonly LoginGuardService $loginGuard,
		private readonly ClockInterface $clock,
		private readonly ExamTime $time,
	) {}

	/** Логин аккаунта с номером `$n`. Единственное место, где он строится. */
	public static function loginFor( int $n ): string {
		return self::LOGIN_PREFIX . $n;
	}

	/**
	 * Создаёт до `CHUNK_MAX` аккаунтов мастер-класса.
	 *
	 * @param int $masterclassId Мастер-класс
	 * @param int $count         Сколько создать, 1–`CHUNK_MAX`
	 * @param int $actorUserId   Кто просит (для журнала и аудита удаления при откате)
	 *
	 * @throws \InvalidArgumentException Размер порции вне границ; мастер-класса нет, он вычищен, помечен к удалению или без группы.
	 *
	 * @return int Сколько аккаунтов создано (занятые логины и сбои не считаются).
	 */
	public function createChunk( int $masterclassId, int $count, int $actorUserId ): int {
		if ( $count < 1 || $count > self::CHUNK_MAX ) {
			throw new \InvalidArgumentException( sprintf( 'За один раз можно создать от 1 до %d аккаунтов.', self::CHUNK_MAX ) );
		}

		$masterclass = $this->requireAcceptingAccounts( $masterclassId );
		$groupId     = (int) $masterclass->groupId;

		$created = 0;
		for ( $i = 0; $i < $count; ++$i ) {
			if ( $this->createOne( $masterclassId, $groupId, $actorUserId ) ) {
				++$created;
			}
		}

		if ( $created > 0 ) {
			$this->log->write(
				MasterclassLogAction::AccountsCreated,
				array(
					'masterclass_id' => $masterclassId,
					'actor'          => $actorUserId,
					'context'        => array( 'count' => $created ),
				)
			);
		}

		return $created;
	}

	/**
	 * Флаеры: живые аккаунты в `Active`, у которых пароль ещё читается (ребёнок его не менял).
	 *
	 * @throws \InvalidArgumentException Мастер-класса нет.
	 *
	 * @return list<MasterclassFlyerDTO>
	 */
	public function flyers( int $masterclassId, int $actorUserId ): array {
		$masterclass = $this->requireExisting( $masterclassId );
		$loginUrl    = PageRoutes::SignIn->url();

		$flyers = array();
		foreach ( $this->accounts->listByMasterclass( $masterclassId ) as $account ) {
			if ( $account->isDeleted() || MasterclassAccountState::Active !== $account->state || null === $account->userId ) {
				continue;
			}

			$credentials = $this->passwords->getCredentials( $account->userId );
			if ( null === $credentials ) {
				continue;
			}

			$flyers[] = new MasterclassFlyerDTO(
				number:     $account->number(),
				login:      self::loginFor( $account->number() ),
				password:   $credentials['password'],
				schoolName: $masterclass->schoolName,
				loginUrl:   $loginUrl,
			);
		}

		$this->log->write(
			MasterclassLogAction::FlyersPrinted,
			array(
				'masterclass_id' => $masterclassId,
				'actor'          => $actorUserId,
				'context'        => array( 'count' => count( $flyers ) ),
			)
		);

		return $flyers;
	}

	/**
	 * Страница списка аккаунтов мастер-класса. Паролей и персональных данных в строках нет.
	 *
	 * @throws \InvalidArgumentException Мастер-класса нет.
	 *
	 * @return array{masterclass: MasterclassDTO, rows: list<array<string, mixed>>, total: int}
	 */
	public function page( int $masterclassId, int $page = 1, int $perPage = 50, bool $withDeleted = false ): array {
		$masterclass = $this->requireExisting( $masterclassId );

		if ( $masterclass->isPurged() ) {
			return array( 'masterclass' => $masterclass, 'rows' => array(), 'total' => 0 );
		}

		$result = $this->accounts->pageByMasterclass( $masterclassId, $page, $perPage, $withDeleted );

		return array(
			'masterclass' => $masterclass,
			'rows'        => array_map( fn ( MasterclassAccountDTO $a ): array => $this->row( $a ), $result['rows'] ),
			'total'       => $result['total'],
		);
	}

	/** Живой аккаунт пользователя: не удалён и не зачислен. */
	public function findLiveByUserId( int $userId ): ?MasterclassAccountDTO {
		$account = $this->accounts->findByUserId( $userId );

		if ( null === $account || $account->isDeleted() || MasterclassAccountState::Enrolled === $account->state ) {
			return null;
		}

		return $account;
	}

	/** Адрес плеера занятия мастер-класса для живого аккаунта; `null` — аккаунта нет или у мастер-класса нет группы. */
	public function playerUrlFor( int $userId ): ?string {
		$account = $this->findLiveByUserId( $userId );
		if ( null === $account ) {
			return null;
		}

		$masterclass = $this->masterclasses->find( $account->masterclassId );
		if ( null === $masterclass || null === $masterclass->groupId || null === $masterclass->groupLessonId ) {
			return null;
		}

		return PageRoutes::LessonPlayer->lessonUrl( $masterclass->groupId, $masterclass->groupLessonId );
	}

	/**
	 * Удаляет аккаунт: метка — ДО события, чтобы обработчик `deleted_user` увидел уже помеченную строку и ничего не сделал.
	 * Повторный вызов (метка уже стоит) ничего не делает. Данные ученика по занятию уйдут вместе с группой.
	 */
	public function deleteAccount( MasterclassAccountDTO $account, AccountDeleteReason $reason, int $actorUserId ): void {
		if ( ! $this->accounts->markDeleted( $account->id, $reason, $this->clock->now( 'mysql', true ) ) ) {
			return;
		}

		if ( null !== $account->personId ) {
			$this->deletion->dispatch( new DeleteStudentEvent( $account->personId, $actorUserId ) );
		}

		$this->log->write(
			MasterclassLogAction::AccountDeleted,
			array(
				'masterclass_id' => $account->masterclassId,
				'account_id'     => $account->id,
				'actor'          => $actorUserId,
				'context'        => array( 'reason' => $reason->value ),
			)
		);
	}

	/**
	 * Отвязывает аккаунт с живой заявкой: снимается только временная запись, учётка и физлицо остаются за заявкой
	 * (`DeleteStudentEvent` стёр бы и её). Строка аккаунта остаётся — воронка её считает. Повтор безвреден.
	 */
	public function detachAccount( MasterclassAccountDTO $account, int $actorUserId ): void {
		if ( null !== $account->personId ) {
			$this->records->deleteTrialByStudent( $account->personId );
		}
		$this->accounts->clearStudentRecord( $account->id );

		$this->log->write(
			MasterclassLogAction::AccountDetached,
			array(
				'masterclass_id' => $account->masterclassId,
				'account_id'     => $account->id,
				'actor'          => $actorUserId,
			)
		);
	}

	/** Есть ли у аккаунта заявка, которая ещё в работе (родитель не заполнил / на проверке / зачисляется). */
	public function hasLiveApplication( MasterclassAccountDTO $account ): bool {
		if ( null === $account->applicationId ) {
			return false;
		}

		$application = $this->applications->find( $account->applicationId );

		return null !== $application && in_array(
			$application->status,
			array( ApplicationStatus::PendingParent, ApplicationStatus::ReadyForReview, ApplicationStatus::Enrolling ),
			true
		);
	}

	/** Сколько живых аккаунтов мастер-класса держат живую заявку (их отвяжут, а не удалят). */
	public function countWithLiveApplication( int $masterclassId ): int {
		$count = 0;
		foreach ( $this->accounts->listByMasterclass( $masterclassId ) as $account ) {
			if ( ! $account->isDeleted() && $this->hasLiveApplication( $account ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Снимает блокировку входа у всех живых аккаунтов (решение 23): класс за одним IP ошибся разом, ждать 15 минут нельзя.
	 *
	 * @throws \InvalidArgumentException Мастер-класса нет или он вычищен.
	 *
	 * @return int Сколько аккаунтов разблокировано.
	 */
	public function unlockLogins( int $masterclassId, int $actorUserId ): int {
		$masterclass = $this->requireExisting( $masterclassId );
		if ( $masterclass->isPurged() ) {
			throw new \InvalidArgumentException( 'Мастер-класс уже вычищен.' );
		}

		$userIds = $this->accounts->liveUserIds( $masterclassId );
		foreach ( $userIds as $userId ) {
			$this->loginGuard->unlockUser( $userId );
		}

		$this->log->write(
			MasterclassLogAction::LoginsUnlocked,
			array(
				'masterclass_id' => $masterclassId,
				'actor'          => $actorUserId,
				'context'        => array( 'count' => count( $userIds ) ),
			)
		);

		return count( $userIds );
	}

	/**
	 * Один аккаунт: номер → физлицо → учётка → временная запись → привязка. Сбой — откат созданного и `create_failed`.
	 *
	 * @return bool Аккаунт создан.
	 */
	private function createOne( int $masterclassId, int $groupId, int $actorUserId ): bool {
		$nowUtc = $this->clock->now( 'mysql', true );
		$number = $this->accounts->insertPending( $masterclassId, $nowUtc );
		$login  = self::loginFor( $number );

		if ( null !== $this->users->findByLogin( $login ) ) {
			$this->accounts->markDeleted( $number, AccountDeleteReason::CreateFailed, $nowUtc );
			return false;
		}

		$personId = 0;
		try {
			$name     = self::NAME_PREFIX . $number;
			$input    = new PersonInputDTO( lastName: $name, firstName: '', docNumber: '' );
			$personId = $this->persons->createOrFindBy( $input );

			$credentials = $this->provisioning->provisionStudent( $personId, $input, $login, $this->passwords->generateReadable() );

			$recordId = $this->records->create(
				new StudentRecordInputDTO(
					studentPersonId:   $personId,
					parentPersonId:    0,
					status:            EnrollmentStatus::Active->value,
					enrolledAt:        $nowUtc,
					createdAt:         $nowUtc,
					updatedAt:         $nowUtc,
					groupId:           $groupId,
					snapshotLastName:  $name,
					snapshotFirstName: '',
					isTrial:           true,
				)
			);
			if ( $recordId <= 0 ) {
				throw new \RuntimeException( 'Не удалось создать запись временного ученика.' );
			}

			$this->accounts->attach( $number, $credentials->userId, $personId, $recordId );
		} catch ( \Throwable $e ) {
			PluginLogger::exception( 'MasterclassAccounts', $e, array( 'masterclass_id' => $masterclassId, 'number' => $number ), true );
			$this->accounts->markDeleted( $number, AccountDeleteReason::CreateFailed, $nowUtc );
			$this->rollBack( $personId, $actorUserId );

			return false;
		}

		return true;
	}

	/** Снимает физлицо с записью и учёткой, если оно успело появиться; сбой отката не мешает порции. */
	private function rollBack( int $personId, int $actorUserId ): void {
		if ( $personId <= 0 ) {
			return;
		}

		try {
			$this->deletion->dispatch( new DeleteStudentEvent( $personId, $actorUserId ) );
		} catch ( \Throwable $e ) {
			PluginLogger::exception( 'MasterclassAccounts', $e, array( 'rollback_person_id' => $personId ), true );
		}
	}

	/**
	 * Строка списка аккаунтов страницы (без паролей и ПД, время местное).
	 *
	 * @return array<string, mixed>
	 */
	private function row( MasterclassAccountDTO $account ): array {
		return array(
			'number'          => $account->number(),
			'login'           => self::loginFor( $account->number() ),
			'state'           => $account->state->label(),
			'firstLoginLocal' => $this->local( $account->firstLoginAt ),
			'homeLocal'       => $this->local( $account->homeAt ),
			'contactLocal'    => $this->local( $account->contactAt ),
			'deletedLocal'    => $this->local( $account->deletedAt ),
			'deleteReason'    => $account->deleteReason?->label(),
			'hasApplication'  => null !== $account->applicationId,
		);
	}

	private function local( ?string $utc ): ?string {
		return null !== $utc ? $this->time->toLocal( $utc ) : null;
	}

	/** @throws \InvalidArgumentException */
	private function requireExisting( int $masterclassId ): MasterclassDTO {
		$masterclass = $this->masterclasses->find( $masterclassId );
		if ( null === $masterclass ) {
			throw new \InvalidArgumentException( 'Мастер-класс не найден.' );
		}

		return $masterclass;
	}

	/** @throws \InvalidArgumentException Вычищен, помечен к удалению или без группы — аккаунтов в него не добавляют. */
	private function requireAcceptingAccounts( int $masterclassId ): MasterclassDTO {
		$masterclass = $this->requireExisting( $masterclassId );

		if ( $masterclass->isPurged() ) {
			throw new \InvalidArgumentException( 'Мастер-класс уже вычищен.' );
		}
		if ( $masterclass->isDeleteRequested() ) {
			throw new \InvalidArgumentException( 'Мастер-класс помечен к удалению — аккаунты в него не добавляются.' );
		}
		if ( null === $masterclass->groupId ) {
			throw new \InvalidArgumentException( 'У мастер-класса нет группы.' );
		}

		return $masterclass;
	}
}
