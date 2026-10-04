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
use Inc\Repositories\WPDBRepositories\PersonRepository;

class AttemptService {

	public function __construct(
		private readonly AssessmentAttemptRepository $attempts,
		private readonly AssessmentAnswerRepository  $answers,
		private readonly AssessmentManager           $assessments,
		private readonly AutoGradeService            $autoGrade,
		private readonly LogEventDispatcherInterface $dispatcher,
		private readonly ClockInterface              $clock,
		private readonly AssessmentAccessPolicy      $access,
		private readonly AttemptRevealPolicy         $revealPolicy,
		private readonly PersonRepository            $persons,
	) {}

	/** В ленту пишется WP-пользователь, а не персона (actor_user_id резолвится через get_userdata()). */
	private function actorUserId( ?int $studentPersonId ): int {
		if ( null === $studentPersonId ) {
			return 0;
		}
		return $this->persons->find( $studentPersonId )?->wpUserId ?? 0;
	}

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
				actorUserId: $this->actorUserId( $studentPersonId ),
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
		$this->saveAnswerFor( $this->requireActiveAttempt( $attemptId, $studentPersonId ), $taskId, $answerText );
	}

	/**
	 * Финальная сдача контрольной.
	 *
	 * @throws \InvalidArgumentException Если попытка не найдена или не принадлежит студенту.
	 * @throws \RuntimeException Если попытка просрочена.
	 */
	public function submit( int $attemptId, int $studentPersonId ): AttemptDTO {
		return $this->submitFor( $this->requireActiveAttempt( $attemptId, $studentPersonId ) );
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

		// Экзаменные попытки завершаются через ExamAttemptService (6.2)
		if ( $attempt->isExam() ) {
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
				actorUserId: $this->actorUserId( $attempt->studentPersonId ),
				groupId    : $attempt->groupId,
				entityType : 'attempt',
				entityId   : (string) $attempt->id,
				isPublic   : false,
			)
		);

		return true;
	}

	/**
	 * Сохранение ответа без проверки владельца и состояния попытки (общее тело и для курса, и для экзаменов 6.1).
	 * Вызывающий уже проверил, чья это попытка, идёт ли она и не истёк ли дедлайн.
	 *
	 * Задание обязано входить в саму работу: task_id приходит из запроса, и без проверки в попытку
	 * можно было дописать ответ на постороннее задание — лист ответов и авто-проверка идут по составу
	 * работы и такую строку не видят.
	 *
	 * @throws \InvalidArgumentException Если задание не входит в работу.
	 */
	public function saveAnswerFor( AttemptDTO $attempt, int $taskId, string $answerText ): void {
		$assessment = $this->assessments->get( $attempt->assessmentId );
		if ( ! $assessment || ! in_array( $taskId, array_map( 'intval', $assessment->taskIds ), true ) ) {
			throw new \InvalidArgumentException( 'Задание не входит в эту работу.' );
		}

		$this->answers->upsert( $attempt->id, $taskId, [ 'answer_text' => $answerText ] );
	}

	/**
	 * Завершение попытки: статус, момент сдачи, событие журнала, автопроверка. Общее тело и для
	 * сдачи учеником, и для сдачи по дедлайну; владельца и состояние проверил вызывающий.
	 *
	 * @param string|null $submittedAt Момент сдачи (местное время); null — сейчас. Автоистечение экзамена
	 *                                 передаёт дедлайн: сдача считается в момент, когда он наступил.
	 *
	 * @throws \RuntimeException Если запись статуса не удалась.
	 */
	public function submitFor( AttemptDTO $attempt, ?string $submittedAt = null ): AttemptDTO {
		$written = $this->attempts->update( $attempt->id, [
			'status'       => AttemptStatus::Submitted->value,
			'submitted_at' => $submittedAt ?? $this->clock->now(),
		] );
		if ( ! $written ) {
			throw new \RuntimeException( 'Не удалось сохранить сдачу попытки.' );
		}

		$submitted = $this->attempts->find( $attempt->id );
		if ( null === $submitted ) {
			throw new \RuntimeException( 'Сданная попытка не найдена.' );
		}

		$this->dispatcher->dispatch(
			LogEvent::AttemptSubmitted,
			new LearningEvent(
				event      : LogEvent::AttemptSubmitted,
				actorUserId: $this->actorUserId( $attempt->studentPersonId ),
				groupId    : $attempt->groupId,
				entityType : 'attempt',
				entityId   : (string) $attempt->id,
				isPublic   : false,
			)
		);

		return $this->autoGrade->gradeAttempt( $submitted );
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

		$answers = $this->answers->listByAttempt( $attemptId );
		if ( ! $this->isRevealed( $attempt ) ) {
			$attempt = $attempt->withoutTotals();

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

	/**
	 * Можно ли отдавать ученику итог и разбор этой попытки. Единственная точка принятия решения для всех
	 * ответов API: результат, сдача, страница станции — иначе один из путей рано или поздно обойдёт политику.
	 */
	public function isRevealed( AttemptDTO $attempt ): bool {
		$assessment = $this->assessments->get( $attempt->assessmentId );
		return null !== $assessment && $this->revealPolicy->isRevealed( $assessment, $attempt );
	}

	/** Валидирует, что попытка активна и принадлежит студенту. */
	private function requireActiveAttempt( int $attemptId, int $studentPersonId ): AttemptDTO {
		$attempt = $this->attempts->find( $attemptId );
		if ( ! $attempt || $attempt->studentPersonId !== $studentPersonId ) {
			throw new \InvalidArgumentException( 'Попытка не найдена.' );
		}

		// Официальную попытку экзамена ведёт только ExamAttemptService: здесь нет ни блокировки участия,
		// ни проверки дедлайна (`expireIfOverdue()` экзамен пропускает) — по этому пути её можно было бы
		// сохранить и сдать после времени.
		if ( $attempt->isExam() ) {
			throw new \RuntimeException( 'Попытка экзамена обрабатывается отдельным путём.' );
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
