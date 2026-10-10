<?php

declare( strict_types=1 );

namespace Inc\Services\Exam;

use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Enums\Wp\TransientKey;
use Inc\Managers\Wp\TransientManager;
use Inc\Repositories\WPDBRepositories\ExamEventRepository;
use Inc\Repositories\WPDBRepositories\ExamPaymentLinkRepository;
use Inc\Services\Exam\Payment\ExamPaymentReconciler;
use Inc\Services\Exam\Payment\WooGateway;
use Inc\Repositories\WPDBRepositories\ExamOperationKeyRepository;
use Inc\Repositories\WPDBRepositories\ExamRegistrationRepository;
use Inc\Shared\PluginLogger;

/**
 * Минутные тики экзаменов: автоистечение просроченных попыток и неявки (6.2–6.3), освобождение истёкших броней гостей (3.4).
 *
 * Тик ничего не решает сам, а выбирает кандидатов и передаёт их сервисам, которые проверяют условие
 * под блокировкой участия: запоздалый или повторный запуск безопасен. Ошибка одной записи логируется и
 * не останавливает остальные. Запуск и защиту от параллельности даёт `CronController` через {@see ExamTickLock}.
 *
 * **Порядок тиков** (шаги внутри тика независимы: сбой шага логируется и не останавливает следующие):
 * - `ExamAutoExpireTick` — автоистечение → неявки → завершение проведений → напоминания;
 * - `ExamHoldReleaseTick` — брони → сверка оплат (реализация — 11a.8) → очистка `exam_operation_keys`;
 * - `ExamOutboxTick` — доставка событий в ленту уведомлений.
 */
class ExamTickService {

	public function __construct(
		private readonly ExamAttemptService $attemptService,
		private readonly ExamNoShowService $noShowService,
		private readonly AssessmentAttemptRepository $attempts,
		private readonly ExamRegistrationRepository $registrations,
		private readonly ExamTime $time,
		private readonly ExamHoldService $holds,
		private readonly ExamEventService $eventService,
		private readonly ExamReminderService $reminders,
		private readonly ExamOutboxWorker $outboxWorker,
		private readonly ExamNotificationComposer $composer,
		private readonly ExamEventRepository $events,
		private readonly ExamOperationKeyRepository $operationKeys,
		private readonly TransientManager $transients,
		private readonly WooGateway $woo,
		private readonly ExamPaymentReconciler $reconciler,
		private readonly ExamPaymentLinkRepository $paymentLinks,
	) {}

	/** Как часто (сек) ученикам аудитории напоминается об открытой записи — чтобы новые ученики получили уведомление (9.2.7). */
	private const AUDIENCE_SYNC_TTL = 600;

	/** Оплата, не подтверждённая хуком за это время, досверяется тиком; за тик — не более LIMIT связей. */
	private const RECONCILE_AFTER_MINUTES = 2;
	private const RECONCILE_LIMIT         = 50;

	/**
	 * Минутный тик целиком: автоистечение → неявки → завершение проведений → напоминания.
	 *
	 * @return array{expired: int, missed: int, completed: int, reminders: int} Попыток завершено, неявок, проведений завершено, напоминаний отправлено.
	 */
	public function autoExpireTick(): array {
		$expired   = $this->step( 'expire', fn (): int => $this->autoExpire() );
		$missed    = $this->step( 'sweep', fn (): int => $this->sweep() );
		$completed = $this->step( 'complete', fn (): int => $this->completeEvents() );
		$reminders = $this->step( 'reminders', fn (): int => $this->sendReminders() );

		return array(
			'expired'   => $expired,
			'missed'    => $missed,
			'completed' => $completed,
			'reminders' => $reminders,
		);
	}

	/** «Скоро», «завтра», «вход открыт» и напоминание об открытой записи новым ученикам аудитории. */
	public function sendReminders(): int {
		return $this->step( 'reminder-soon', fn (): int => $this->reminders->soon() )
			+ $this->step( 'reminder-tomorrow', fn (): int => $this->reminders->tomorrow() )
			+ $this->step( 'reminder-entry', fn (): int => $this->reminders->entryOpened() )
			+ $this->step( 'audience-sync', fn (): int => $this->syncAudience() );
	}

	/**
	 * Новый ученик аудитории после публикации получает «открыта запись» один раз (ключ `exam:opened:{проведение}`, уже получившие повтора не получат).
	 * Не чаще раза в 10 минут: время прогона хранится в транзиенте.
	 *
	 * @return int Сколько проведений обработано (0 — рано или нет открытых).
	 */
	public function syncAudience(): int {
		if ( false !== $this->transients->get( TransientKey::ExamAudienceSync, 'last' ) ) {
			return 0;
		}
		$this->transients->set( TransientKey::ExamAudienceSync, 'last', $this->time->nowUtc(), self::AUDIENCE_SYNC_TTL );

		$count = 0;
		foreach ( $this->events->listOpenForRegistration( $this->time->nowUtc() ) as $event ) {
			$this->composer->announceOpening( $event );
			++$count;
		}

		return $count;
	}

	/**
	 * Тик доставки событий outbox в ленту уведомлений.
	 *
	 * @return int Сколько событий доставлено.
	 */
	public function deliverEvents( int $limit = 100 ): int {
		return $this->outboxWorker->run( $limit );
	}

	/**
	 * Завершение проведений, где всё закончено (8.3.5). Условие проверяется под блокировкой проведения: запоздалый и повторный запуск безопасны.
	 *
	 * @return int Сколько проведений завершено.
	 */
	public function completeEvents( int $limit = 100 ): int {
		$count = 0;

		foreach ( $this->eventService->completionCandidates( $limit ) as $eventId ) {
			try {
				if ( $this->eventService->completeIfDone( $eventId ) ) {
					++$count;
				}
			} catch ( \Throwable $e ) {
				PluginLogger::exception( 'ExamTick', $e, array( 'event_id' => $eventId ), true );
			}
		}

		return $count;
	}

	/**
	 * Тик броней: освобождает места, занятые истёкшими бронями гостей (каждая бронь — в своей транзакции под блокировкой заявки и сеанса,
	 * поэтому повторный и параллельный запуск освобождает место ровно один раз), затем сверка оплат и очистка ключей идемпотентности.
	 *
	 * @param int $limit Максимум броней за один тик
	 *
	 * @return int Сколько броней освобождено
	 */
	public function releaseHolds( int $limit = 100 ): int {
		// Сначала сверка оплат: оплата, пришедшая за секунду до истечения, успевает подтвердить ещё живую бронь.
		$this->step( 'reconcile', function (): int {
			$this->reconcilePayments();
			return 0;
		} );
		$this->step( 'recheck-needs-help', fn (): int => $this->reconciler->refreshWaitingForHelp() );
		$released = $this->step( 'holds', fn (): int => $this->holds->releaseExpired( $limit ) );
		$this->step( 'purge-keys', fn (): int => $this->operationKeys->purgeExpired( $this->time->nowUtc() ) );

		return $released;
	}

	/**
	 * Сверка неподтверждённых оплат гостей (11a.8): связи `pending` старше двух минут, не более 50 за тик. Ошибка одной связи
	 * не останавливает остальные. При выключенном WooCommerce ничего не делает.
	 */
	public function reconcilePayments(): void {
		if ( ! $this->woo->isActive() ) {
			return;
		}

		$orders = array();
		foreach ( $this->paymentLinks->listPendingForReconcile( $this->time->addMinutes( $this->time->nowUtc(), -self::RECONCILE_AFTER_MINUTES ), self::RECONCILE_LIMIT ) as $link ) {
			$orders[ $link->wcOrderId ] = true;
		}

		foreach ( array_keys( $orders ) as $orderId ) {
			try {
				$this->reconciler->reconcileOrder( $orderId );
			} catch ( \Throwable $e ) {
				PluginLogger::exception( 'ExamTick', $e, array( 'order_id' => $orderId ), true );
			}
		}
	}

	/**
	 * Шаг тика: сбой логируется и не останавливает следующие шаги.
	 *
	 * @param callable():int $fn
	 */
	private function step( string $name, callable $fn ): int {
		try {
			return (int) $fn();
		} catch ( \Throwable $e ) {
			PluginLogger::exception( 'ExamTick', $e, array( 'step' => $name ), true );
			return 0;
		}
	}

	/**
	 * Автоистечение просроченных попыток (6.2.2): `submitted_at = deadline_at`, обычная автопроверка.
	 *
	 * @param int $limit Максимум попыток за один тик
	 *
	 * @return int Количество завершённых попыток
	 */
	public function autoExpire( int $limit = 200 ): int {
		$count = 0;

		foreach ( $this->attempts->listOverdueExamIds( $this->time->nowLocal(), $limit ) as $attemptId ) {
			try {
				if ( $this->attemptService->finalizeExpired( $attemptId ) ) {
					++$count;
				}
			} catch ( \Throwable $e ) {
				PluginLogger::exception( 'ExamTick', $e, array( 'attempt_id' => $attemptId ), true );
			}
		}

		return $count;
	}

	/**
	 * Неявки по истечении сеансов (6.3.4): действующая запись сеанса, плановый конец которого наступил, без попытки.
	 *
	 * @param int $limit Максимум записей за один тик
	 *
	 * @return int Количество проставленных неявок
	 */
	public function sweep( int $limit = 200 ): int {
		$count = 0;

		foreach ( $this->registrations->listActiveOfEndedSessions( $this->time->nowUtc(), $limit ) as $registration ) {
			try {
				if ( $this->noShowService->markMissed( $registration->id ) ) {
					++$count;
				}
			} catch ( \Throwable $e ) {
				PluginLogger::exception( 'ExamTick', $e, array( 'registration_id' => $registration->id ), true );
			}
		}

		return $count;
	}
}
