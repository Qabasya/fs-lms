<?php

declare( strict_types=1 );

namespace Inc\Services\Exam;

use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Repositories\WPDBRepositories\ExamRegistrationRepository;
use Inc\Shared\PluginLogger;

/**
 * Минутные тики экзаменов: автоистечение просроченных попыток и неявки (6.2–6.3), освобождение истёкших броней гостей (3.4).
 *
 * Тик ничего не решает сам, а выбирает кандидатов и передаёт их сервисам, которые проверяют условие
 * под блокировкой участия: запоздалый или повторный запуск безопасен. Ошибка одной записи логируется и
 * не останавливает остальные. Запуск и защиту от параллельности даёт `CronController` через {@see ExamTickLock}.
 */
class ExamTickService {

	public function __construct(
		private readonly ExamAttemptService $attemptService,
		private readonly ExamNoShowService $noShowService,
		private readonly AssessmentAttemptRepository $attempts,
		private readonly ExamRegistrationRepository $registrations,
		private readonly ExamTime $time,
		private readonly ExamHoldService $holds,
	) {}

	/**
	 * Минутный тик целиком: сначала автоистечение, затем неявки.
	 *
	 * @return array{expired: int, missed: int} Сколько попыток завершено и сколько неявок проставлено.
	 */
	public function autoExpireTick(): array {
		return array(
			'expired' => $this->autoExpire(),
			'missed'  => $this->sweep(),
		);
	}

	/**
	 * Тик броней: освобождает места, занятые истёкшими бронями гостей. Каждая бронь — в своей транзакции под блокировкой заявки
	 * и сеанса, поэтому повторный и параллельный запуск освобождает место ровно один раз.
	 *
	 * @param int $limit Максимум броней за один тик
	 *
	 * @return int Сколько броней освобождено
	 */
	public function releaseHolds( int $limit = 100 ): int {
		return $this->holds->releaseExpired( $limit );
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
