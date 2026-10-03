<?php

declare( strict_types=1 );

namespace Inc\Services\Exam;

use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Repositories\WPDBRepositories\ExamRegistrationRepository;
use Inc\Shared\PluginLogger;

/**
 * Минутный крон для управления экзаменными попытками (6.2-6.3).
 *
 * @package Inc\Services\Exam
 */
class ExamTickService {

	public function __construct(
		private readonly ExamAttemptService $attemptService,
		private readonly ExamNoShowService $noShowService,
		private readonly AssessmentAttemptRepository $attemptRepo,
		private readonly ExamRegistrationRepository $registrationRepo,
		private readonly ExamTime $time,
	) {}

	/**
	 * Автоистечение просроченных попыток (6.2.2).
	 * Вызывается из минутного крона.
	 *
	 * @param int $limit Максимум попыток за один тик
	 *
	 * @return int Количество завершённых попыток
	 */
	public function autoExpire( int $limit = 200 ): int {
		$nowLocal = $this->time->nowLocal();
		$ids = $this->attemptRepo->listOverdueExamIds( $nowLocal, $limit );

		$count = 0;
		foreach ( $ids as $attemptId ) {
			try {
				if ( $this->attemptService->finalizeExpired( $attemptId ) ) {
					++$count;
				}
			} catch ( \Exception $e ) {
				PluginLogger::exception( 'ExamTick', $e, array( 'attempt_id' => $attemptId ), true );
			}
		}

		return $count;
	}

	/**
	 * Отметить неявки по истечении сеансов (6.3.4).
	 * Вызывается из минутного крона после autoExpire.
	 *
	 * @param int $limit Максимум записей за один тик
	 *
	 * @return int Количество отмеченных неявок
	 */
	public function sweep( int $limit = 200 ): int {
		$nowUtc = $this->time->nowUtc();
		$registrations = $this->registrationRepo->listActiveOfEndedSessions( $nowUtc, $limit );

		$count = 0;
		foreach ( $registrations as $registration ) {
			try {
				if ( $this->noShowService->markMissed( $registration->id ) ) {
					++$count;
				}
			} catch ( \Exception $e ) {
				PluginLogger::exception( 'ExamTick', $e, array( 'registration_id' => $registration->id ), true );
			}
		}

		return $count;
	}
}
