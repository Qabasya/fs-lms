<?php

declare( strict_types=1 );

namespace Inc\Services\Assessment;

use Inc\Contracts\ClockInterface;
use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Assessment\AttemptAnswerDTO;
use Inc\DTO\Assessment\AttemptDTO;
use Inc\DTO\Assessment\AttemptInputDTO;
use Inc\DTO\Log\Events\LearningEvent;
use Inc\Enums\Assessment\AttemptStatus;
use Inc\Enums\Log\LogEvent;
use Inc\Managers\Assessment\AssessmentManager;
use Inc\Repositories\WPDBRepositories\AssessmentAnswerRepository;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;

class AttemptService {

	public function __construct(
		private readonly AssessmentAttemptRepository $attempts,
		private readonly AssessmentAnswerRepository  $answers,
		private readonly AssessmentManager           $assessments,
		private readonly AutoGradeService            $autoGrade,
		private readonly LogEventDispatcherInterface $dispatcher,
		private readonly ClockInterface              $clock,
		private readonly AssessmentAccessPolicy      $access,
		private readonly EgeCompletenessChecker      $completeness,
		private readonly AttemptRevealPolicy         $revealPolicy,
	) {}

	/**
	 * Старт попытки.
	 *
	 * @throws \RuntimeException Если исчерпан лимит попыток или дублирующий INSERT (двойной клик).
	 */
	public function start( int $studentPersonId, int $assessmentId, ?int $groupId, ?int $groupLessonId = null ): AttemptDTO {
		$assessment = $this->assessments->get( $assessmentId );
		if ( ! $assessment ) {
			throw new \InvalidArgumentException( "Экзамен {$assessmentId} не найден." );
		}

		// Занятие берём у политики, а не из запроса: `from_gl` есть только при заходе
		// из плеера, а по прямому пермалинку (закладка, возврат к активной попытке)
		// его нет — и попытка оставалась без привязки к занятию.
		$accessibleLesson = $this->access->resolveAccessibleLesson( $studentPersonId, $assessmentId );
		if ( null === $accessibleLesson ) {
			throw new \RuntimeException( 'Нет доступа к этой контрольной.' );
		}

		$groupLessonId ??= $accessibleLesson->id;
		$groupId       ??= $accessibleLesson->groupId;

		// D16.3.б: незавершённую ЕГЭ/КЕГЭ-работу (нет биекции задание↔номер) нельзя
		// начать. Control не касается. Опубликованная работа обычно уже прошла
		// блок публикации (D16.3.а), но проверяем и здесь — на случай прямого старта.
		if ( $assessment->kind->needsCompletenessCheck()
			&& ! $this->completeness->validate( $assessment, $assessment->subjectKey )->isStrictlyComplete()
		) {
			throw new \RuntimeException( 'Работа не укомплектована — обратитесь к преподавателю.' );
		}

		if ( $assessment->attemptsAllowed > 0 ) {
			$used = $this->attempts->countByAssessmentAndStudent( $assessmentId, $studentPersonId );
			if ( $used >= $assessment->attemptsAllowed ) {
				throw new \RuntimeException( 'Исчерпан лимит попыток.' );
			}
		}

		$now          = $this->clock->now();
		$deadlineAt   = $assessment->timeLimit > 0
			? date( 'Y-m-d H:i:s', strtotime( $now ) + $assessment->timeLimit * 60 )
			: date( 'Y-m-d H:i:s', strtotime( $now ) + 100 * YEAR_IN_SECONDS );
		$attemptNumber = $this->attempts->nextAttemptNumber( $studentPersonId, $assessmentId );

		$dto = new AttemptInputDTO(
			assessmentId    : $assessmentId,
			studentPersonId : $studentPersonId,
			groupId         : $groupId,
			attemptNumber   : $attemptNumber,
			startedAt       : $now,
			deadlineAt      : $deadlineAt,
			groupLessonId   : $groupLessonId,
		);

		$id = $this->attempts->create( $dto );
		if ( $id === 0 ) {
			throw new \RuntimeException( 'Не удалось создать попытку (возможно, гонка двойного клика).' );
		}

		$attempt = $this->attempts->find( $id );
		assert( $attempt !== null );

		$this->dispatcher->dispatch(
			LogEvent::AttemptStarted,
			new LearningEvent(
				event      : LogEvent::AttemptStarted,
				actorUserId: $studentPersonId,
				groupId    : $groupId,
				entityType : 'attempt',
				entityId   : (string) $id,
				isPublic   : false,
			)
		);

		return $attempt;
	}

	/**
	 * Сохранение ответа (autosave / промежуточная запись).
	 *
	 * @throws \InvalidArgumentException Если попытка не найдена или не принадлежит студенту.
	 * @throws \RuntimeException Если попытка просрочена или уже завершена.
	 */
	public function saveAnswer( int $attemptId, int $taskId, string $answerText, int $studentPersonId ): void {
		$attempt = $this->requireActiveAttempt( $attemptId, $studentPersonId );

		// Задание обязано входить в саму работу: task_id приходит из запроса, и без
		// проверки в попытку можно было дописать ответ на постороннее задание —
		// лист ответов и авто-проверка идут по составу работы и такую строку не видят.
		$assessment = $this->assessments->get( $attempt->assessmentId );
		if ( ! $assessment || ! in_array( $taskId, array_map( 'intval', $assessment->taskIds ), true ) ) {
			throw new \InvalidArgumentException( 'Задание не входит в эту работу.' );
		}

		$this->answers->upsert( $attempt->id, $taskId, [ 'answer_text' => $answerText ] );
	}

	/**
	 * Финальная сдача контрольной.
	 *
	 * @throws \InvalidArgumentException Если попытка не найдена или не принадлежит студенту.
	 * @throws \RuntimeException Если попытка просрочена.
	 */
	public function submit( int $attemptId, int $studentPersonId ): AttemptDTO {
		$attempt = $this->requireActiveAttempt( $attemptId, $studentPersonId );

		$now = $this->clock->now();
		$this->attempts->update( $attempt->id, [
			'status'       => AttemptStatus::Submitted->value,
			'submitted_at' => $now,
		] );

		$submitted = $this->attempts->find( $attempt->id );
		assert( $submitted !== null );

		$this->dispatcher->dispatch(
			LogEvent::AttemptSubmitted,
			new LearningEvent(
				event      : LogEvent::AttemptSubmitted,
				actorUserId: $studentPersonId,
				groupId    : $attempt->groupId,
				entityType : 'attempt',
				entityId   : (string) $attempt->id,
				isPublic   : false,
			)
		);

		return $this->autoGrade->gradeAttempt( $submitted );
	}

	/**
	 * Ленивая проверка и проставление expired.
	 *
	 * @return bool true если попытка была просрочена и помечена expired.
	 */
	public function expireIfOverdue( int $attemptId ): bool {
		$attempt = $this->attempts->find( $attemptId );
		if ( ! $attempt || $attempt->status !== AttemptStatus::InProgress ) {
			return false;
		}

		if ( ! $attempt->isExpired( $this->clock->now() ) ) {
			return false;
		}

		$this->attempts->update( $attempt->id, [ 'status' => AttemptStatus::Expired->value ] );

		$this->dispatcher->dispatch(
			LogEvent::AttemptExpired,
			new LearningEvent(
				event      : LogEvent::AttemptExpired,
				actorUserId: $attempt->studentPersonId,
				groupId    : $attempt->groupId,
				entityType : 'attempt',
				entityId   : (string) $attempt->id,
				isPublic   : false,
			)
		);

		return true;
	}

	/**
	 * Результат попытки для отображения.
	 *
	 * D18: вердикт/балл каждого ответа (не только эталонный текст, которого тут и
	 * так нет) зачищается, пока учитель не подтвердил результат — этот метод
	 * дёргает AJAX-эндпоинт, доступный станции КЕГЭ/ОГЭ даже после сдачи, поэтому
	 * гейт нужен здесь самостоятельно, а не только на уровне рендера finish.php.
	 *
	 * @return array{attempt: AttemptDTO, answers: AttemptAnswerDTO[]}
	 */
	public function getResult( int $attemptId, int $studentPersonId ): array {
		$attempt = $this->attempts->find( $attemptId );
		if ( ! $attempt || $attempt->studentPersonId !== $studentPersonId ) {
			throw new \InvalidArgumentException( 'Попытка не найдена.' );
		}

		$this->expireIfOverdue( $attemptId );

		$attempt = $this->attempts->find( $attemptId );
		assert( $attempt !== null );

		$assessment = $this->assessments->get( $attempt->assessmentId );
		$revealed   = null !== $assessment && $this->revealPolicy->isRevealed( $assessment, $attempt );

		$answers = $this->answers->listByAttempt( $attemptId );
		if ( ! $revealed ) {
			$answers = array_map( static fn( AttemptAnswerDTO $a ): AttemptAnswerDTO => new AttemptAnswerDTO(
				id            : $a->id,
				attemptId     : $a->attemptId,
				taskId        : $a->taskId,
				answerText    : $a->answerText,
				isCorrect     : null,
				score         : null,
				maxScore      : $a->maxScore,
				gradedByUserId: null,
				gradedAt      : null,
				graderNote    : null,
				criteriaScores: null,
			), $answers );
		}

		return [
			'attempt' => $attempt,
			'answers' => $answers,
		];
	}

	/** Валидирует, что попытка активна и принадлежит студенту. */
	private function requireActiveAttempt( int $attemptId, int $studentPersonId ): AttemptDTO {
		$attempt = $this->attempts->find( $attemptId );
		if ( ! $attempt || $attempt->studentPersonId !== $studentPersonId ) {
			throw new \InvalidArgumentException( 'Попытка не найдена.' );
		}

		if ( $attempt->status !== AttemptStatus::InProgress ) {
			throw new \RuntimeException( 'Попытка уже завершена.' );
		}

		if ( $this->expireIfOverdue( $attemptId ) ) {
			throw new \RuntimeException( 'Время попытки истекло.' );
		}

		return $attempt;
	}
}
