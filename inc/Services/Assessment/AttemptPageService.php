<?php

declare( strict_types=1 );

namespace Inc\Services\Assessment;

use Inc\Contracts\ClockInterface;
use Inc\DTO\Assessment\AssessmentDTO;
use Inc\DTO\Assessment\AttemptDTO;
use Inc\DTO\Assessment\AttemptPageDTO;
use Inc\Enums\Assessment\AttemptStatus;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Services\Course\GroupAccessGuard;

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
	 * @param GroupAccessGuard            $groupGuard Управляет ли пользователь группой попытки (просмотр чужой попытки)
	 */
	public function __construct(
		private AssessmentAttemptRepository $attempts,
		private PersonRepository            $persons,
		private AssessmentAccessPolicy      $access,
		private AttemptResultService        $results,
		private AttemptOutcomeService       $outcome,
		private AttemptTaskViewBuilder      $taskViews,
		private ClockInterface              $clock,
		private GroupAccessGuard            $groupGuard,
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

		// Tasks.md, п. 8: сколько попыток израсходовано — экран интро/результата
		// предупреждает о предпоследней и последней.
		$attemptsUsed = $this->attempts->countByAssessmentAndStudent( $assessment->id, $person->id );

		return new AttemptPageDTO(
			person:         $person,
			activeAttempt:  $activeAttempt,
			lastAttempt:    $lastAttempt,
			examInProgress: $examInProgress,
			taskViews:      $this->taskViews->build( $assessment->taskIds, $assessment->subjectKey, $assessment->kind ),
			resultPerTask:  $resultPerTask,
			outcome:        $outcomeLabel,
			outcomeState:   $outcomeState,
			canRetry:       $assessment->attemptsAllowed <= 0 || $attemptsUsed < $assessment->attemptsAllowed,
			now:            $now,
			attemptsUsed:   $attemptsUsed,
		);
	}

	/**
	 * Просмотр конкретной попытки станции: экран результата (лист ответов) именно этой
	 * попытки, `?attempt=ID`. Открыть может:
	 *  - тот, кто управляет группой попытки (преподаватель группы, замена, автор курсов,
	 *    админ) — результат ему открыт сразу, не дожидаясь «Утвердить работу»;
	 *  - сам ученик — только свою попытку, и результат открывается по обычному правилу
	 *    ({@see AttemptRevealPolicy}): до утверждения ему показывается «обрабатывается».
	 *
	 * Ничего не пишется. Незавершённая (идущая) попытка не показывается: у неё нет результата.
	 *
	 * @param AssessmentDTO $assessment Экзамен
	 * @param int           $attemptId  Попытка
	 * @param int           $userId     ID пользователя WP
	 *
	 * @return AttemptPageDTO|null null — попытки нет, она чужая или недоступна этому пользователю
	 */
	public function buildReview( AssessmentDTO $assessment, int $attemptId, int $userId ): ?AttemptPageDTO {
		$attempt = $this->attempts->find( $attemptId );
		if ( null === $attempt || $attempt->assessmentId !== $assessment->id || AttemptStatus::InProgress === $attempt->status ) {
			return null;
		}

		$person  = $this->persons->findByWpUserId( $userId );
		$isOwner = null !== $person && $person->id === $attempt->studentPersonId;
		$manages = ! $isOwner && $this->canManageAttempt( $attempt, $assessment, $userId );
		if ( ! $isOwner && ! $manages ) {
			return null;
		}

		$student = $isOwner ? $person : $this->persons->find( $attempt->studentPersonId );

		return new AttemptPageDTO(
			person:         $student,
			activeAttempt:  null,
			lastAttempt:    $attempt,
			examInProgress: false,
			taskViews:      $this->taskViews->build( $assessment->taskIds, $assessment->subjectKey, $assessment->kind ),
			resultPerTask:  $this->results->studentPerTask( $attempt->id, $attempt->studentPersonId ),
			outcome:        $this->outcome->label( $attempt, $assessment ),
			outcomeState:   $this->outcome->state( $attempt, $assessment ),
			canRetry:       false,
			now:            $this->clock->now(),
			reviewMode:     true,
			reviewReveal:   $manages,
		);
	}

	/** Управляет ли пользователь группой попытки; попытка вне группы — только автор/сотрудник контрольной. */
	private function canManageAttempt( AttemptDTO $attempt, AssessmentDTO $assessment, int $userId ): bool {
		if ( null !== $attempt->groupId && $attempt->groupId > 0 ) {
			return $this->groupGuard->canManage( $attempt->groupId, $userId );
		}

		return $this->access->canPreview( $userId, $assessment->id );
	}

	/**
	 * Состояние страницы публичного экзамена: как предпросмотр — ни ученика, ни
	 * попытки, в БД ничего не пишется (ответы живут в браузере), — но без
	 * авторской плашки. Доступ решает модуль публичных экзаменов
	 * ({@see \Inc\Controllers\Pages\AssessmentPageController::PUBLIC_ACCESS_FILTER}).
	 *
	 * @param AssessmentDTO $assessment Экзамен
	 */
	public function buildPublic( AssessmentDTO $assessment ): AttemptPageDTO {
		return new AttemptPageDTO(
			person:         null,
			activeAttempt:  null,
			lastAttempt:    null,
			examInProgress: false,
			taskViews:      $this->taskViews->build( $assessment->taskIds, $assessment->subjectKey, $assessment->kind ),
			resultPerTask:  array(),
			outcome:        '',
			outcomeState:   'fail',
			canRetry:       false,
			now:            $this->clock->now(),
			publicMode:     true,
		);
	}

	/**
	 * Холостое состояние страницы для предпросмотра автора: заданий столько же,
	 * сколько увидит ученик, но ученика нет, попытка не заводится и в БД ничего
	 * не пишется — станция рисуется «вхолостую» (см. AssessmentPageController).
	 *
	 * @param AssessmentDTO $assessment Контрольная
	 */
	public function buildPreview( AssessmentDTO $assessment ): AttemptPageDTO {
		return new AttemptPageDTO(
			person:         null,
			activeAttempt:  null,
			lastAttempt:    null,
			examInProgress: false,
			taskViews:      $this->taskViews->build( $assessment->taskIds, $assessment->subjectKey, $assessment->kind ),
			resultPerTask:  array(),
			outcome:        '',
			outcomeState:   'fail',
			canRetry:       false,
			now:            $this->clock->now(),
			previewMode:    true,
		);
	}

}
