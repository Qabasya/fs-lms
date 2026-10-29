<?php

declare( strict_types=1 );

namespace Inc\Modules\Masterclass\Services;

use Inc\Contracts\ClockInterface;
use Inc\DTO\Enrollment\StudentDataDTO;
use Inc\Managers\Person\UserManager;
use Inc\Modules\Masterclass\DTO\MasterclassAccountDTO;
use Inc\Modules\Masterclass\Enums\MasterclassAccountState;
use Inc\Modules\Masterclass\Enums\MasterclassContactKind;
use Inc\Modules\Masterclass\Enums\MasterclassLogAction;
use Inc\Modules\Masterclass\Repositories\MasterclassAccountRepository;
use Inc\Modules\Masterclass\Repositories\MasterclassRepository;
use Inc\Repositories\WPDBRepositories\ApplicationRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Services\Enrollment\TrialAccessService;
use Inc\Services\Security\PiiCryptoService;
use Inc\Shared\PluginLogger;

/**
 * Class MasterclassConversionService
 *
 * Воронка «вошёл → вернулся домой → нажал «Связаться» → подал заявку → зачислен».
 *
 * ### Заявка
 *
 * Ребёнок открывает `/apply/` залогиненным под `student-N`. Заявку создаёт ядро как обычную (физлицо ученика у неё
 * пока не задано), а по хуку `fs_lms_application_created` модуль привязывает её к физлицу аккаунта (`trial_owner = 1`:
 * учётка принадлежит заявке) и переносит введённые логин и пароль на учётку. Аккаунт определяется по сессии, не по
 * параметру запроса. Смена логина и пароля обрывает сессию: кука входа подписана логином и фрагментом хеша пароля.
 *
 * ### Зачисление
 *
 * `EnrollmentService` переиспользует физлицо и учётку, дописывает в физлицо данные заявки и снимает временную запись;
 * модуль по `fs_lms_student_enrolled` ставит «зачислен», отпускает запись аккаунта (группа мастер-класса снесётся без
 * него) и переписывает имя учётки WP из физлица — иначе шапка кабинета осталась бы «Ученик#N».
 *
 * Привязка заявки наружу исключений не бросает: сбой не должен ронять ответ формы заявки, который уже ушёл ребёнку.
 *
 * @package Inc\Modules\Masterclass\Services
 */
class MasterclassConversionService {

	public function __construct(
		private readonly MasterclassAccountRepository $accounts,
		private readonly MasterclassRepository $masterclasses,
		private readonly MasterclassAccountService $accountService,
		private readonly ApplicationRepository $applications,
		private readonly PersonRepository $persons,
		private readonly UserManager $users,
		private readonly TrialAccessService $trialAccess,
		private readonly PiiCryptoService $crypto,
		private readonly MasterclassLogWriter $log,
		private readonly ClockInterface $clock,
	) {}

	/**
	 * Клик в окне «Связаться». Любой вид клика ставит одноразовую отметку «связался»; каждый клик попадает в журнал.
	 * Не аккаунт мастер-класса (или удалён/зачислен) — молча ничего: по ответу запроса это не должно быть видно.
	 */
	public function logContact( int $userId, MasterclassContactKind $kind ): void {
		$account = $this->liveAccount( $this->accounts->findByUserId( $userId ) );
		if ( null === $account ) {
			return;
		}

		$this->accounts->markContact( $account->id, $this->nowUtc() );

		$this->log->write(
			MasterclassLogAction::fromContact( $kind ),
			array(
				'account_id'     => $account->id,
				'masterclass_id' => $account->masterclassId,
				'actor'          => $userId,
			)
		);
	}

	/**
	 * Привязывает только что созданную заявку к аккаунту пользователя и переносит логин и пароль заявки на учётку.
	 *
	 * Пользователь без аккаунта мастер-класса (гость, обычный ученик) — тихий отказ без записи в журнал: заявки подаются
	 * постоянно. Остальные отказы и сбои пишутся как `ApplicationBindFailed` без данных заявки.
	 *
	 * @return bool `true` — заявка привязана.
	 */
	public function bindApplication( int $applicationId, int $userId ): bool {
		$account = $this->liveAccount( $this->accounts->findByUserId( $userId ) );
		if ( null === $account ) {
			return false;
		}

		try {
			return $this->bind( $account, $applicationId );
		} catch ( \Throwable $e ) {
			PluginLogger::exception( 'MasterclassConversion', $e, array( 'application_id' => $applicationId ), true );

			return $this->bindFailed( $account, $applicationId, 'exception' );
		}
	}

	/**
	 * Зачисление (`fs_lms_student_enrolled`): аккаунт становится «зачислен» и больше модулю не принадлежит.
	 *
	 * @return bool `true` — аккаунт переведён; `false` — у физлица нет живого аккаунта мастер-класса (обычное зачисление).
	 */
	public function markEnrolled( int $personId ): bool {
		$account = $this->liveAccount( $this->accounts->findByPersonId( $personId ) );
		if ( null === $account ) {
			return false;
		}

		$this->accounts->setState( $account->id, MasterclassAccountState::Enrolled );
		// Временную запись ядро уже сняло; ссылка на неё не нужна, а «зачисленного» тик не выбирает.
		$this->accounts->clearStudentRecord( $account->id );
		$this->renameUser( $account, $personId );

		$this->log->write(
			MasterclassLogAction::Enrolled,
			array(
				'account_id'     => $account->id,
				'masterclass_id' => $account->masterclassId,
			)
		);

		return true;
	}

	/**
	 * Предзаполнение формы заявки для ребёнка с живым аккаунтом (Ш5): направление и школа мастер-класса.
	 *
	 * @return array<string, string>
	 */
	public function prefill( int $userId ): array {
		$account = $this->liveAccount( $this->accounts->findByUserId( $userId ) );
		if ( null === $account ) {
			return array();
		}

		$masterclass = $this->masterclasses->find( $account->masterclassId );
		if ( null === $masterclass ) {
			return array();
		}

		return array(
			'subject_key' => $masterclass->subjectKey,
			'school'      => $masterclass->schoolName,
		);
	}

	/** Живой аккаунт: есть, не удалён и не зачислен; иначе `null`. */
	private function liveAccount( ?MasterclassAccountDTO $account ): ?MasterclassAccountDTO {
		if ( null === $account || $account->isDeleted() || MasterclassAccountState::Enrolled === $account->state ) {
			return null;
		}

		return $account;
	}

	/**
	 * Порядок важен при сбое посередине: сначала расшифровка (ничего ещё не записано), потом привязка и отметка
	 * состояния, и только в конце перенос логина и пароля.
	 */
	private function bind( MasterclassAccountDTO $account, int $applicationId ): bool {
		if ( null === $account->personId ) {
			return $this->bindFailed( $account, $applicationId, 'no_person' );
		}

		if ( $this->accountService->hasLiveApplication( $account ) ) {
			return $this->bindFailed( $account, $applicationId, 'live_application' );
		}

		$application = $this->applications->find( $applicationId );
		if ( null === $application ) {
			return $this->bindFailed( $account, $applicationId, 'application_not_found' );
		}

		if ( null !== $application->studentPersonId ) {
			return $this->bindFailed( $account, $applicationId, 'application_already_bound' );
		}

		$student = StudentDataDTO::fromArray(
			json_decode( $this->crypto->decrypt( (string) $application->studentDataEnc ), true ) ?? array()
		);

		$updated = $this->applications->update(
			$applicationId,
			array(
				'student_person_id' => $account->personId,
				'trial_owner'       => 1,
				'updated_at'        => $this->nowUtc(),
			)
		);
		if ( ! $updated ) {
			throw new \RuntimeException( 'Заявка не обновилась.' );
		}

		$this->accounts->setState( $account->id, MasterclassAccountState::Applied, $applicationId );
		$this->trialAccess->syncCredentials( $applicationId, $student->username, $student->loginPassword );

		$this->log->write(
			MasterclassLogAction::ApplicationBound,
			array(
				'account_id'     => $account->id,
				'masterclass_id' => $account->masterclassId,
				'actor'          => (int) $account->userId,
				'context'        => array( 'application_id' => $applicationId ),
			)
		);

		return true;
	}

	/** Пишет отказ привязки в журнал; всегда `false`. */
	private function bindFailed( MasterclassAccountDTO $account, int $applicationId, string $reason ): bool {
		$this->log->write(
			MasterclassLogAction::ApplicationBindFailed,
			array(
				'account_id'     => $account->id,
				'masterclass_id' => $account->masterclassId,
				'actor'          => (int) $account->userId,
				'result'         => 'failure',
				'context'        => array(
					'application_id' => $applicationId,
					'reason'         => $reason,
				),
			)
		);

		return false;
	}

	/** Имя учётки WP — из физлица («Фамилия Имя»), по единому правилу `PersonDTO::accountName()`. */
	private function renameUser( MasterclassAccountDTO $account, int $personId ): void {
		$person = $this->persons->find( $personId );
		if ( null === $person || null === $account->userId ) {
			return;
		}

		$this->users->update(
			$account->userId,
			array(
				'display_name' => $person->accountName(),
				'first_name'   => $person->firstName,
				'last_name'    => $person->lastName,
			)
		);
	}

	private function nowUtc(): string {
		return $this->clock->now( 'mysql', true );
	}
}
