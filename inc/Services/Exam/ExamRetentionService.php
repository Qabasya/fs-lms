<?php

declare( strict_types=1 );

namespace Inc\Services\Exam;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Log\Events\EntityChangedEvent;
use Inc\Enums\Exam\ExamTokenPurpose;
use Inc\Enums\Exam\GuestApplicationState;
use Inc\Enums\Log\EntityType;
use Inc\Enums\Log\LogEvent;
use Inc\Enums\Log\OperationType;
use Inc\Managers\Wp\MediaManager;
use Inc\Repositories\WPDBRepositories\AssessmentAnswerRepository;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Repositories\WPDBRepositories\ExamAccessTokenRepository;
use Inc\Repositories\WPDBRepositories\ExamEventRepository;
use Inc\Repositories\WPDBRepositories\ExamGuestApplicationRepository;
use Inc\Repositories\WPDBRepositories\ExamGuestSessionRepository;
use Inc\Repositories\WPDBRepositories\ExamOutboxEventRepository;
use Inc\Repositories\WPDBRepositories\ExamParticipantRepository;
use Inc\Repositories\WPDBRepositories\ExamParticipationRepository;
use Inc\Services\Shared\PluginConfig;
use Inc\Enums\Log\ErrorCode;
use Inc\Shared\CodedException;
use Inc\Shared\Traits\TransactionRunner;

/**
 * Хранение и удаление данных гостей (этап 13.3, SPEC §9): срок после завершения проведения — настройка (по умолчанию 365 дней),
 * неоплаченные заявки — 30 дней. По истечении доступы отзываются, идентифицирующие данные очищаются; действующий `Person` не затрагивается.
 *
 * Попытки и ответы остаются (агрегатная статистика без идентификации), `school_name` остаётся для агрегатов. Заказы WooCommerce не удаляются никогда:
 * шлюз магазина сервису не передаётся. Запускается ежедневным `RetentionCleanup`; повтор безопасен (обезличивание — условный `UPDATE`).
 */
class ExamRetentionService {

	use TransactionRunner;

	public const LOCK = 'exam_retention';

	/** Состояния заявок, чьи черновики очищаются по сроку неоплаченных. */
	private const STALE_STATES = array(
		GuestApplicationState::ExpiredUnpaid->value,
		GuestApplicationState::Failed->value,
		GuestApplicationState::Cancelled->value,
	);

	/** Технический мусор (обработанные события, истёкшие ключи и сессии) хранится 30 дней. */
	private const TECH_DAYS = 30;

	public function __construct(
		private readonly ExamParticipantRepository $participants,
		private readonly ExamParticipationRepository $participations,
		private readonly ExamGuestApplicationRepository $applications,
		private readonly AssessmentAttemptRepository $attempts,
		private readonly AssessmentAnswerRepository $answers,
		private readonly ExamAccessTokenService $tokens,
		private readonly ExamAccessTokenRepository $tokenRows,
		private readonly ExamGuestSessionRepository $sessionRows,
		private readonly GuestSessionService $sessions,
		private readonly ExamOutboxEventRepository $outbox,
		private readonly MediaManager $media,
		private readonly PluginConfig $config,
		private readonly LogEventDispatcherInterface $logEvents,
		private readonly ExamTickLock $lock,
		private readonly ExamTime $time,
		private readonly ExamEventRepository $events,
		private readonly ExamAccessGuard $guard,
	) {}

	/**
	 * Ежедневный обход под блокировкой: гости, неоплаченные заявки, технический мусор.
	 *
	 * @return array{guests: int, applications: int, purged: int}|null null — обход уже идёт.
	 */
	public function runDaily(): ?array {
		$result = null;
		$this->lock->run( self::LOCK, function () use ( &$result ): void {
			$result = array(
				'guests'       => $this->sweepGuests(),
				'applications' => $this->sweepUnpaidApplications(),
				'purged'       => $this->purgeTechnical(),
			);
		} );

		return $result;
	}

	/**
	 * Обезличивает **только гостя** (без `person_id`): ключи входа и результата и сессии отзываются, ФИО, телефон, мессенджер и их хеши
	 * очищаются, файлы ответов гостя удаляются. Участник с `person_id` — `false` без изменений. Попытки и ответы остаются.
	 *
	 * @return bool true — участник обезличен этим вызовом.
	 */
	public function anonymizeParticipant( int $participantId, string $reason, int $actorUserId = 0 ): bool {
		$files = array();

		$done = $this->inTransactionWithRetry( function () use ( $participantId, &$files ): bool {
			$participant = $this->participants->findForUpdate( $participantId );
			if ( null === $participant || null !== $participant->personId || null !== $participant->anonymizedAt ) {
				return false;
			}

			foreach ( $this->participations->findByParticipant( $participantId ) as $participation ) {
				$this->tokens->revoke( ExamTokenPurpose::Entry, $participation->id );
				$this->tokens->revoke( ExamTokenPurpose::Result, $participation->id );
				$this->sessions->revokeByParticipation( $participation->id );
				if ( null !== $participation->currentAttemptId ) {
					$files = array_merge( $files, $this->answerFileIds( $participation->currentAttemptId ) );
				}
			}

			return $this->participants->anonymize( $participantId, $this->time->nowUtc() );
		} );

		if ( ! $done ) {
			return false;
		}

		// Файлы удаляются после фиксации: сбой удаления вложения не должен откатывать обезличивание.
		foreach ( array_unique( $files ) as $attachmentId ) {
			$this->media->delete( $attachmentId );
		}
		$this->logEvents->dispatch( LogEvent::ExamGuestAnonymized, new EntityChangedEvent( $actorUserId, OperationType::Update, EntityType::ExamParticipant, $participantId, $reason ) );

		return true;
	}

	/**
	 * Запрос гостя на удаление: ручное действие сотрудника (права `ManageExamGuests` и `ManageLmsPlatform` проверяет коллбек). Причина
	 * обязательна; сотрудник должен управлять **каждым** проведением участника: чужое обезличить нельзя.
	 *
	 * @throws CodedException
	 */
	public function anonymizeByStaff( int $actorUserId, int $participantId, string $reason ): void {
		$reason = trim( $reason );
		if ( '' === $reason ) {
			throw new CodedException( ErrorCode::ExamConflict, 'Укажите причину.' );
		}

		$participant = $this->participants->find( $participantId );
		if ( null === $participant || null !== $participant->personId ) {
			throw new CodedException( ErrorCode::ExamAccess, 'Гость не найден.' );
		}
		foreach ( $this->participations->findByParticipant( $participantId ) as $participation ) {
			$event = $this->events->find( $participation->eventId );
			if ( null === $event || ! $this->guard->canManageEventGuests( $actorUserId, $event ) ) {
				throw new CodedException( ErrorCode::ExamAccess, 'Гость не найден.' );
			}
		}

		if ( ! $this->anonymizeParticipant( $participantId, $reason, $actorUserId ) ) {
			throw new CodedException( ErrorCode::ExamConflict, 'Данные гостя уже обезличены.' );
		}
	}

	/**
	 * Гости, у которых все проведения завершены или отменены раньше срока хранения и нет открытых ручных разборов оплаты.
	 *
	 * @return int Сколько обезличено.
	 */
	public function sweepGuests( int $limit = 200 ): int {
		$cutoff = $this->time->addMinutes( $this->time->nowUtc(), -$this->config->examGuestRetentionDays() * 1440 );
		$count  = 0;
		foreach ( $this->participants->listGuestIdsDueForAnonymization( $cutoff, $limit ) as $participantId ) {
			if ( $this->anonymizeParticipant( $participantId, 'срок хранения истёк' ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Неоплаченные заявки старше срока: черновик и хеш адреса очищаются, снимок источника остаётся. Не трогает заявки с несверенной оплатой,
	 * `paid_needs_resolution` и `confirmed`. Заказы WooCommerce не удаляются.
	 *
	 * @return int Сколько заявок очищено.
	 */
	public function sweepUnpaidApplications( int $limit = 500 ): int {
		$cutoff = $this->time->addMinutes( $this->time->nowUtc(), -$this->config->examUnpaidRetentionDays() * 1440 );

		return $this->applications->clearStaleDrafts( self::STALE_STATES, $cutoff, $limit );
	}

	/**
	 * Обработанные события outbox, истёкшие ключи и сессии старше 30 дней.
	 *
	 * @return int Сколько строк удалено.
	 */
	public function purgeTechnical(): int {
		$cutoff = $this->time->addMinutes( $this->time->nowUtc(), -self::TECH_DAYS * 1440 );

		return $this->outbox->purgeProcessedBefore( $cutoff ) + $this->tokenRows->purgeInactiveBefore( $cutoff ) + $this->sessionRows->purgeInactiveBefore( $cutoff );
	}

	/**
	 * ID вложений, приложенных гостем к ответам попытки (`{"text":…,"files":[id]}`).
	 *
	 * @return list<int>
	 */
	private function answerFileIds( int $attemptId ): array {
		$ids = array();
		foreach ( $this->answers->listByAttempt( $attemptId ) as $answer ) {
			$decoded = null !== $answer->answerText ? json_decode( $answer->answerText, true ) : null;
			foreach ( is_array( $decoded ) && is_array( $decoded['files'] ?? null ) ? $decoded['files'] : array() as $id ) {
				if ( (int) $id > 0 ) {
					$ids[] = (int) $id;
				}
			}
		}

		return $ids;
	}
}
