<?php

declare( strict_types=1 );

namespace Inc\Services\Exam;

use Inc\DTO\Assessment\AttemptDTO;
use Inc\Enums\Course\WorkSourceType;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Repositories\WPDBRepositories\ExamEventRepository;
use Inc\Repositories\WPDBRepositories\ExamParticipationRepository;
use Inc\Services\Assessment\AttemptService;
use Inc\Services\Course\WorkDetailService;

/**
 * Разбор экзаменной попытки для «Работ» преподавателя (`manage`) и ученика/родителя (`read_only`) (7.1.4).
 *
 * Права проекция не проверяет: вызывающий код сам решает, кому можно смотреть (ученик — свою попытку
 * по `forStudent()`, сотрудник — по праву на проведение). Раскрытие же проверяется здесь всегда:
 * до утверждения `read_only` не получает ни заданий, ни баллов, ни эталонов. Решение о раскрытии
 * принимает одна точка — {@see AttemptService::isRevealed()}, общая со всеми ответами API.
 */
class ExamReviewProjection {

	public const MODE_MANAGE    = 'manage';
	public const MODE_READ_ONLY = 'read_only';

	/** Ключи корня, которые ученику ни к чему и раскрывают служебное устройство оценивания. */
	private const ROOT_GRADING_KEYS = array( 'attempt_id', 'group_id', 'student_name', 'review_url', 'gradable', 'submission_id' );

	/** Поля задачи, нужные только для оценивания; набранные по критериям баллы (`criteria`) остаются. */
	private const TASK_GRADING_KEYS = array( 'task_id', 'manual', 'oge_rubric', 'review_url' );

	public function __construct(
		private readonly WorkDetailService $details,
		private readonly AssessmentAttemptRepository $attempts,
		private readonly ExamParticipationRepository $participations,
		private readonly ExamEventRepository $events,
		private readonly AttemptService $attemptService,
		private readonly ExamScoreService $scores,
	) {}

	/**
	 * @param string $mode `manage` | `read_only`
	 *
	 * @return array<string, mixed>|null null — попытка не найдена или не экзаменная.
	 */
	public function forViewer( int $attemptId, string $mode ): ?array {
		$attempt = $this->attempts->find( $attemptId );
		if ( null === $attempt || ! $attempt->isExam() ) {
			// Режим проверяем и здесь: опечатка не должна тихо превратиться в «нет такой попытки».
			$this->assertMode( $mode );
			return null;
		}

		return $this->assemble( $attempt, $mode )['view'] ?? null;
	}

	/**
	 * Разбор для ученика (или родителя ребёнка): попытка — текущая попытка участия этого человека
	 * в этом проведении. Идентификатор попытки из запроса не принимается.
	 *
	 * @return array<string, mixed>|null null — участия или попытки нет (ответ «Результат недоступен.» без подробностей).
	 */
	public function forStudent( int $personId, int $eventId ): ?array {
		$participation = $this->participations->findByEventAndPerson( $eventId, $personId );
		$event         = $this->events->find( $eventId );
		if ( null === $participation || null === $event || null === $participation->currentAttemptId ) {
			return null;
		}

		$attempt = $this->attempts->find( $participation->currentAttemptId );
		if ( null === $attempt || $attempt->examParticipationId !== $participation->id ) {
			return null;
		}

		return $this->readOnlyWithResult( $attempt, $event );
	}

	/**
	 * Разбор по участию (гость по своей сессии, школьный отчёт по составу отчёта): попытка — текущая попытка этого участия, номер из запроса не принимается.
	 * Гостю разбор раскрыт сразу после сдачи ({@see AttemptService::isRevealed()}); до сдачи — `revealed = false`.
	 *
	 * @return array<string, mixed>|null null — участия или попытки нет.
	 */
	public function forParticipation( int $participationId ): ?array {
		$participation = $this->participations->find( $participationId );
		$event         = null !== $participation ? $this->events->find( $participation->eventId ) : null;
		if ( null === $participation || null === $event || null === $participation->currentAttemptId ) {
			return null;
		}

		$attempt = $this->attempts->find( $participation->currentAttemptId );
		if ( null === $attempt || $attempt->examParticipationId !== $participation->id ) {
			return null;
		}

		return $this->readOnlyWithResult( $attempt, $event );
	}

	/**
	 * Очищенный разбор + итог и единицы, если работа раскрыта.
	 *
	 * @return array<string, mixed>|null
	 */
	private function readOnlyWithResult( AttemptDTO $attempt, \Inc\DTO\Exam\ExamEventDTO $event ): ?array {
		$assembled = $this->assemble( $attempt, self::MODE_READ_ONLY );
		if ( null === $assembled ) {
			return null;
		}

		$review = $assembled['view'];
		if ( true !== $review['revealed'] ) {
			return $review;
		}

		// Единицы считаются по полному разбору (им нужен task_id), а ученику уходит очищенный.
		$review['result'] = $this->scores->summarize( $attempt, $event );
		$review['units']  = $this->scores->units( $attempt, $assembled['tasks'] );

		return $review;
	}

	/**
	 * Общая сборка для обоих входов: проверка режима, раскрытие, разбор работы, очистка под режим.
	 * Проверки доступа у входов разные и остаются в них.
	 *
	 * @return array{view: array<string, mixed>, tasks: array<int, array<string, mixed>>}|null
	 *         `tasks` — задания до очистки (нужны для единиц оценивания); null — разбор недоступен.
	 */
	private function assemble( AttemptDTO $attempt, string $mode ): ?array {
		$this->assertMode( $mode );

		if ( self::MODE_READ_ONLY === $mode && ! $this->attemptService->isRevealed( $attempt ) ) {
			// Ни заданий, ни баллов: разбор данных не собирается вовсе.
			return array(
				'view'  => array(
					'revealed' => false,
					'status'   => $attempt->status->value,
				),
				'tasks' => array(),
			);
		}

		$detail = $this->details->forWork( WorkSourceType::Attempt->value, $attempt->id );
		if ( null === $detail ) {
			return null;
		}
		$tasks = (array) ( $detail['tasks'] ?? array() );

		if ( self::MODE_MANAGE === $mode ) {
			$detail['result_version'] = $attempt->resultVersion;
			$detail['revealed']       = true;
			return array( 'view' => $detail, 'tasks' => $tasks );
		}

		return array(
			'view'  => $this->stripGrading( $detail ) + array( 'revealed' => true ),
			'tasks' => $tasks,
		);
	}

	private function assertMode( string $mode ): void {
		if ( self::MODE_MANAGE !== $mode && self::MODE_READ_ONLY !== $mode ) {
			throw new \InvalidArgumentException( 'Неизвестный режим разбора: ' . $mode );
		}
	}

	/**
	 * @param array<string, mixed> $detail
	 *
	 * @return array<string, mixed>
	 */
	private function stripGrading( array $detail ): array {
		foreach ( self::ROOT_GRADING_KEYS as $key ) {
			unset( $detail[ $key ] );
		}

		$detail['tasks'] = array_map(
			static function ( array $task ): array {
				foreach ( self::TASK_GRADING_KEYS as $key ) {
					unset( $task[ $key ] );
				}
				return $task;
			},
			(array) ( $detail['tasks'] ?? array() )
		);

		return $detail;
	}
}
