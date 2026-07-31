<?php

declare( strict_types=1 );

namespace Inc\Services\Assessment;

use Inc\Contracts\ClockInterface;
use Inc\DTO\Assessment\AssessmentDTO;
use Inc\DTO\Assessment\AttemptPageDTO;
use Inc\Enums\Assessment\AttemptStatus;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;

/**
 * Class AttemptPageService
 *
 * Собирает состояние страницы прохождения контрольной: кто открыл, есть ли
 * активная попытка, что показывать (задания или результат) и можно ли пройти ещё раз.
 *
 * @package Inc\Services\Assessment
 *
 * Доступ решает {@see AssessmentAccessPolicy}; HTTP-побочки (редирект гостя, 404,
 * заголовки, выбор рендерера) остаются в контроллере страницы.
 */
readonly class AttemptPageService {

	/**
	 * @param AssessmentAttemptRepository $attempts   Попытки прохождения
	 * @param PersonRepository            $persons    Физлица (ученик по user_id)
	 * @param AssessmentAccessPolicy      $access     Гейт доступа к контрольной
	 * @param AttemptResultService        $results    Результаты по заданиям
	 * @param AttemptOutcomeService       $outcome    Метка/состояние исхода
	 * @param AttemptTaskViewBuilder      $taskViews  Per-task данные для шаблона
	 * @param ClockInterface              $clock      Текущее время
	 */
	public function __construct(
		private AssessmentAttemptRepository $attempts,
		private PersonRepository            $persons,
		private AssessmentAccessPolicy      $access,
		private AttemptResultService        $results,
		private AttemptOutcomeService       $outcome,
		private AttemptTaskViewBuilder      $taskViews,
		private ClockInterface              $clock,
	) {}

	/**
	 * Состояние страницы для текущего пользователя.
	 *
	 * @param AssessmentDTO $assessment Контрольная
	 * @param int           $userId     ID пользователя WP (0 — гость)
	 *
	 * @return AttemptPageDTO|null null — ученик не найден или доступа нет (контроллер отдаёт 404)
	 */
	public function build( AssessmentDTO $assessment, int $userId ): ?AttemptPageDTO {
		$person = $this->persons->findByWpUserId( $userId );
		if ( null === $person || ! $this->access->canAccess( $person->id, $assessment->id ) ) {
			return null;
		}

		$now           = $this->clock->now();
		$activeAttempt = $this->attempts->findActive( $person->id, $assessment->id );

		// Пока идёт активная попытка — bare-шелл убирает кнопку «Вернуться»: выйти
		// из контрольной можно только сдав её (иначе уход со страницы оставил бы
		// попытку in_progress и заблокировал курс).
		$examInProgress = null !== $activeAttempt
			&& AttemptStatus::InProgress === $activeAttempt->status
			&& ! $activeAttempt->isExpired( $now );

		// T13.7: нет активной попытки — показываем результат последней сданной.
		$lastAttempt   = null;
		$resultPerTask = array();
		$outcomeLabel  = '';
		$outcomeState  = 'fail';
		if ( ! $activeAttempt ) {
			$lastAttempt = $this->attempts->findLastSubmitted( $person->id, $assessment->id );
			if ( $lastAttempt ) {
				$resultPerTask = $this->results->studentPerTask( $lastAttempt->id, $person->id );
				$outcomeLabel  = $this->outcome->label( $lastAttempt, $assessment );
				$outcomeState  = $this->outcome->state( $lastAttempt, $assessment );
			}
		}

		return new AttemptPageDTO(
			person:         $person,
			activeAttempt:  $activeAttempt,
			lastAttempt:    $lastAttempt,
			examInProgress: $examInProgress,
			taskViews:      $this->taskViews->build( $assessment->taskIds, $assessment->subjectKey, $assessment->kind ),
			resultPerTask:  $resultPerTask,
			outcome:        $outcomeLabel,
			outcomeState:   $outcomeState,
			canRetry:       $this->canRetry( $assessment, $person->id ),
			now:            $now,
		);
	}

	/**
	 * Можно ли начать ещё попытку (кнопка «Пройти ещё раз» на экране результата):
	 * без лимита (0) — всегда; иначе — пока использовано меньше лимита.
	 *
	 * @param AssessmentDTO $assessment Контрольная
	 * @param int           $personId   Ученик
	 */
	private function canRetry( AssessmentDTO $assessment, int $personId ): bool {
		return $assessment->attemptsAllowed <= 0
			|| $this->attempts->countByAssessmentAndStudent( $assessment->id, $personId ) < $assessment->attemptsAllowed;
	}
}
