<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\Enums\Course\SubmissionStatus;
use Inc\Enums\Course\WorkSourceType;
use Inc\Managers\Assessment\AssessmentManager;
use Inc\Managers\Course\WorkManager;
use Inc\Repositories\WPDBRepositories\AssessmentAnswerRepository;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Repositories\WPDBRepositories\SubmissionRepository;

/**
 * Class WorkMarksService
 *
 * Пооответная «полоска» работы для карточек кабинета (Tasks.md, п. 7): вердикт
 * каждого задания сдачи в порядке самой работы/контрольной — галочка, крестик
 * или «на проверке». Без баллов и условий: карточка списка показывает только
 * форму результата, разбор — в детали работы ({@see WorkDetailService}).
 *
 * Один сервис на оба источника результата, потому что карточка в «Сводке по
 * ученику» и карточка в очереди проверки — одна и та же вёрстка.
 *
 * @package Inc\Services\Course
 */
class WorkMarksService {

	/** Вердикт задания: решено / не решено / ждёт ручной проверки. */
	private const string CORRECT   = 'correct';
	private const string INCORRECT = 'incorrect';
	private const string PENDING   = 'pending';

	public function __construct(
		private readonly SubmissionRepository        $submissions,
		private readonly WorkManager                 $works,
		private readonly AssessmentAttemptRepository $attempts,
		private readonly AssessmentAnswerRepository  $answers,
		private readonly AssessmentManager           $assessments,
	) {}

	/**
	 * Вердикты заданий сдачи в порядке работы.
	 *
	 * @param string $sourceType `submission` (работа) | `attempt` (контрольная)
	 * @param int    $sourceId   ID агрегатной строки сдачи / попытки
	 *
	 * @return string[] Значения `correct` | `incorrect` | `pending`; пусто — разбора по заданиям нет
	 */
	public function marksFor( string $sourceType, int $sourceId ): array {
		return match ( WorkSourceType::fromValueOrNull( $sourceType ) ) {
			WorkSourceType::Submission => $this->fromSubmission( $sourceId ),
			WorkSourceType::Attempt    => $this->fromAttempt( $sourceId ),
			default                    => array(),
		};
	}

	/**
	 * Работа: авто-вердикты лежат JSON-снапшотом в агрегатной строке, но по
	 * ручным заданиям авторитетна per-task строка — её пишет проверка учителя
	 * ({@see SubmissionService::gradeBatchTask()}), снапшот при этом не трогая.
	 *
	 * @return string[]
	 */
	private function fromSubmission( int $submissionId ): array {
		$sub = $this->submissions->find( $submissionId );
		if ( null === $sub || null !== $sub->taskId ) {
			return array();
		}

		$snapshot = json_decode( (string) $sub->answerText, true );
		$snapshot = is_array( $snapshot ) ? $snapshot : array();

		$rowsByTask = array();
		foreach ( $this->submissions->listPerTaskByStudentWorkLesson( $sub->studentPersonId, $sub->groupLessonId, $sub->workId ) as $row ) {
			if ( null !== $row->taskId ) {
				$rowsByTask[ $row->taskId ] = $row;
			}
		}

		$work    = $this->works->get( $sub->workId );
		$taskIds = $work?->itemIds ? array_map( 'intval', $work->itemIds ) : array_map( 'intval', array_keys( $snapshot ) );

		$marks = array();
		foreach ( $taskIds as $taskId ) {
			$row = $rowsByTask[ $taskId ] ?? null;

			if ( null !== $row && SubmissionStatus::PendingReview === $row->status ) {
				$marks[] = self::PENDING;
				continue;
			}
			if ( null !== $row && SubmissionStatus::Graded === $row->status ) {
				$marks[] = ( $row->score ?? 0.0 ) >= ( $row->maxScore ?? 1.0 ) ? self::CORRECT : self::INCORRECT;
				continue;
			}

			$marks[] = $this->normalize( $snapshot[ $taskId ]['verdict'] ?? null );
		}

		return $marks;
	}

	/**
	 * Контрольная: строки ответов идут в порядке вставки (как ученик отвечал) —
	 * перекладываем в порядок заданий контрольной, как {@see WorkDetailService::fromAttempt()}.
	 *
	 * @return string[]
	 */
	private function fromAttempt( int $attemptId ): array {
		$attempt = $this->attempts->find( $attemptId );
		if ( null === $attempt ) {
			return array();
		}

		$byTask = array();
		foreach ( $this->answers->listByAttempt( $attemptId ) as $answer ) {
			$byTask[ $answer->taskId ] = $answer;
		}

		$assessment = $this->assessments->get( $attempt->assessmentId );
		$ordered    = array();
		foreach ( $assessment?->taskIds ?? array() as $taskId ) {
			$taskId = (int) $taskId;
			if ( isset( $byTask[ $taskId ] ) ) {
				$ordered[] = $byTask[ $taskId ];
				unset( $byTask[ $taskId ] );
			}
		}
		$ordered = array_merge( $ordered, array_values( $byTask ) );

		$marks = array();
		foreach ( $ordered as $answer ) {
			$marks[] = null === $answer->isCorrect
				? self::PENDING
				: ( $answer->isCorrect ? self::CORRECT : self::INCORRECT );
		}

		return $marks;
	}

	/** Вердикт из снапшота автопроверки; неизвестное значение — «на проверке». */
	private function normalize( mixed $verdict ): string {
		return in_array( $verdict, array( self::CORRECT, self::INCORRECT ), true )
			? (string) $verdict
			: self::PENDING;
	}
}
