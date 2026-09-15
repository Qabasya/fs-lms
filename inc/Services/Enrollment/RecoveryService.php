<?php

declare( strict_types=1 );

namespace Inc\Services\Enrollment;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Application\ApplicationDTO;
use Inc\DTO\Enrollment\StudentDataDTO;
use Inc\DTO\Log\Events\ApplicationStatusEvent;
use Inc\DTO\Person\ParentDataDTO;
use Inc\Enums\Enrollment\ApplicationStatus;
use Inc\Enums\Log\AuditAction;
use Inc\Enums\Log\LogEvent;
use Inc\Repositories\WPDBRepositories\ApplicationRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Application\ApplicationService;
use Inc\Services\Security\PiiCryptoService;
use Inc\Shared\PluginLogger;

/**
 * Class RecoveryService
 *
 * Досоздание того, что зачисление не успело (cron `RecoveryTick`, раз в 15 минут).
 *
 * @package Inc\Services\Enrollment
 *
 * ### Два случая
 *
 * 1. **Зависшее `enrolling`** — администратор начал зачисление и не закончил, либо процесс упал
 *    посреди него. Нет записи о зачислении — заявка возвращается в «Готова к зачислению»;
 *    запись есть (транзакция прошла) — заявка помечается `converted` и дальше идёт случаем 2.
 *    Порог — {@see STUCK_ENROLLING_MINUTES}: модалка зачисления бывает открыта долго, а cron
 *    не должен выбивать из неё администратора.
 * 2. **`converted` без учёток** — транзакция прошла, а создание WP-учёток упало
 *    ({@see EnrollmentService::enroll()}). Учётки создаются тем же {@see EnrollmentAccountsService},
 *    с логином и паролем, которые задал ученик; создаётся только недостающая, пароль уже
 *    существующей не трогается. После этого заявка удаляется — как при обычном зачислении.
 *
 * Письмо родителю восстановление не отправляет: была ли при зачислении отмечена отправка письма,
 * не сохраняется, а данные для входа видны в карточке ученика и родителя.
 */
readonly class RecoveryService {

	/** Через сколько минут `enrolling` считается зависшим. */
	public const STUCK_ENROLLING_MINUTES = 60;

	public function __construct(
		private ApplicationRepository       $applicationRepository,
		private StudentRecordRepository     $studentRecordRepository,
		private PersonRepository            $personRepository,
		private LogEventDispatcherInterface $logEvents,
		private ApplicationService          $applications,
		private EnrollmentAccountsService   $accounts,
		private PiiCryptoService            $crypto,
	) {}

	/**
	 * @return int Сколько заявок обработано
	 */
	public function resolveStuckEnrollments(): int {
		$resolved = 0;

		foreach ( $this->applicationRepository->findStuckEnrolling( self::STUCK_ENROLLING_MINUTES ) as $app ) {
			try {
				$record = null !== $app->studentPersonId
					? $this->studentRecordRepository->findActiveByStudentFirst( $app->studentPersonId )
					: null;

				if ( null === $record ) {
					$this->applications->changeStatus( $app->id, ApplicationStatus::ReadyForReview );
				} else {
					// Транзакция прошла, до учёток дело не дошло — ниже их досоздаст проход по `converted`.
					$this->applicationRepository->markConverted( $app->id, $record->id );
				}

				++$resolved;
			} catch ( \Throwable $e ) {
				PluginLogger::warning( 'Recovery', 'Не удалось обработать зависшее зачисление', array( 'application_id' => $app->id, 'error' => $e->getMessage() ) );
			}
		}

		foreach ( $this->applicationRepository->findConverted() as $app ) {
			try {
				if ( $this->completeAccounts( $app ) ) {
					++$resolved;
				}
			} catch ( \Throwable $e ) {
				// Заявка остаётся `converted` — следующий запуск cron попробует снова.
				PluginLogger::warning( 'Recovery', 'Не удалось создать учётки после зачисления', array( 'application_id' => $app->id, 'error' => $e->getMessage() ) );
			}
		}

		return $resolved;
	}

	/**
	 * Создаёт недостающие учётки по заявке `converted` и удаляет заявку.
	 *
	 * @param ApplicationDTO $app Заявка
	 *
	 * @return bool false — запись о зачислении не найдена, заявку не трогаем
	 *
	 * @throws \Throwable Ошибка расшифровки или создания учётки
	 */
	private function completeAccounts( ApplicationDTO $app ): bool {
		$recordId = (int) ( $app->convertedRecordId ?? 0 );
		$record   = $recordId > 0 ? $this->studentRecordRepository->find( $recordId ) : null;

		if ( null === $record ) {
			PluginLogger::warning( 'Recovery', 'У заявки converted нет записи о зачислении', array( 'application_id' => $app->id ) );
			return false;
		}

		$student = $this->personRepository->find( $record->studentPersonId );

		if ( null !== $student && null === $student->wpUserId ) {
			$this->accounts->provisionStudent( $this->decryptStudent( $app ), $record->studentPersonId );
		}

		if ( $record->parentPersonId > 0 ) {
			$guardian = $this->personRepository->find( $record->parentPersonId );

			if ( null !== $guardian && null === $guardian->wpUserId ) {
				$this->accounts->provisionGuardian( $this->decryptParent( $app ), $record->parentPersonId );
			}
		}

		$this->applicationRepository->forceDelete( $app->id );

		$this->logEvents->dispatch(
			LogEvent::ApplicationUpdated,
			new ApplicationStatusEvent( 0, AuditAction::RecoveryCompleted, $app->id )
		);

		return true;
	}

	private function decryptStudent( ApplicationDTO $app ): StudentDataDTO {
		return StudentDataDTO::fromArray( $this->decrypt( $app->studentDataEnc ) );
	}

	private function decryptParent( ApplicationDTO $app ): ParentDataDTO {
		return ParentDataDTO::fromArray( $this->decrypt( $app->parentDataEnc ) );
	}

	/**
	 * @param string|null $encrypted Зашифрованный JSON
	 *
	 * @return array<string, mixed>
	 *
	 * @throws \RuntimeException Данных нет — учётку создать не из чего
	 */
	private function decrypt( ?string $encrypted ): array {
		if ( empty( $encrypted ) ) {
			throw new \RuntimeException( 'В заявке нет данных для создания учётки.' );
		}

		$data = json_decode( $this->crypto->decrypt( $encrypted ), true );

		return is_array( $data ) ? $data : array();
	}
}
