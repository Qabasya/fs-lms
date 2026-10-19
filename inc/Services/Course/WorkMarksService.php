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
use Inc\Services\Task\CorrectAnswerResolver;

/**
 * Class WorkMarksService
 *
 * Пооответная «полоска» работы для карточек кабинета (Tasks.md, п. 7): вердикт
 * каждого задания сдачи в порядке самой работы/контрольной — галочка, крестик
 * «не решено» (ответа не было) или «на проверке». Без баллов и условий: карточка списка показывает только
 * форму результата, разбор — в детали работы ({@see WorkDetailService}).
 *
 * Один сервис на оба источника результата, потому что карточка в «Сводке по
 * ученику» и карточка в очереди проверки — одна и та же вёрстка.
 *
 * @package Inc\Services\Course
 */
class WorkMarksService {

	/** Вердикт задания: решено / решено с исправлением / не решено / ждёт ручной проверки. */
	private const string CORRECT   = 'correct';
	/** Верно в итоге, но проверка кнопкой до сдачи сначала показала ошибку. */
	private const string CORRECTED = 'corrected';
	private const string INCORRECT = 'incorrect';
	/** Ответа на задание не было вовсе: красный квадрат с прочерком. */
	private const string UNANSWERED = 'unanswered';
	private const string PENDING   = 'pending';

	public function __construct(
		private readonly SubmissionRepository        $submissions,
		private readonly WorkManager                 $works,
		private readonly AssessmentAttemptRepository $attempts,
		private readonly AssessmentAnswerRepository  $answers,
		private readonly AssessmentManager           $assessments,
		private readonly CorrectAnswerResolver       $correctAnswers,
	) {}

	/**
	 * Вердикты заданий сдачи в порядке работы.
	 *
	 * @param string $sourceType `submission` (работа) | `attempt` (контрольная)
	 * @param int    $sourceId   ID агрегатной строки сдачи / попытки
	 *
	 * @return string[] Значения `correct` | `corrected` | `incorrect` | `unanswered` | `pending`; пусто — разбора по заданиям нет
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
			// Строки первой сдачи прошлых версий лежат без баллов — вердикт тогда из снапшота.
			if ( null !== $row && SubmissionStatus::Graded === $row->status && null !== $row->score ) {
				$marks[] = ( $row->score ?? 0.0 ) >= ( $row->maxScore ?? 1.0 )
					? $this->correctMark( $snapshot[ $taskId ] ?? array() )
					: $this->wrongMark( $row );
				continue;
			}

			$verdict = $this->normalize( $snapshot[ $taskId ]['verdict'] ?? null );
			if ( self::INCORRECT === $verdict ) {
				$verdict = $this->wrongMark( $row );
			}
			$marks[] = self::CORRECT === $verdict ? $this->correctMark( $snapshot[ $taskId ] ) : $verdict;
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
		$marks      = array();
		foreach ( $assessment?->taskIds ?? array() as $taskId ) {
			$taskId = (int) $taskId;
			// Задания без строки ответа — ученик к ним не приступал: «не решено».
			$marks[] = isset( $byTask[ $taskId ] ) ? $this->attemptMark( $byTask[ $taskId ] ) : self::UNANSWERED;
			unset( $byTask[ $taskId ] );
		}
		foreach ( $byTask as $answer ) {
			$marks[] = $this->attemptMark( $answer );
		}

		return $marks;
	}

	/** Отметка строки ответа контрольной: пусто — на проверке; верный — решено; неверный пустой ответ — не решено. */
	private function attemptMark( object $answer ): string {
		if ( null === $answer->isCorrect ) {
			return self::PENDING;
		}
		if ( $answer->isCorrect ) {
			return self::CORRECT;
		}

		return $this->correctAnswers->isEmptyStudentAnswer( $answer->answerText ) ? self::UNANSWERED : self::INCORRECT;
	}

	/**
	 * Неверное задание сдачи: если ответа не было (нет строки или она пуста) — «не решено», иначе «неверно».
	 * Оценка преподавателя вердикт не меняет: выставленный вручную ноль остаётся «неверно».
	 */
	private function wrongMark( ?object $row ): string {
		if ( null !== $row?->gradedByUserId ) {
			return self::INCORRECT;
		}

		return $this->correctAnswers->isEmptyStudentAnswer( $row?->answerText ) ? self::UNANSWERED : self::INCORRECT;
	}

	/** Жёлтая отметка вместо зелёной, если в снапшоте задача помечена исправленной. */
	private function correctMark( mixed $verdict ): string {
		return is_array( $verdict ) && ! empty( $verdict['corrected'] ) ? self::CORRECTED : self::CORRECT;
	}

	/** Вердикт из снапшота автопроверки; неизвестное значение — «на проверке». */
	private function normalize( mixed $verdict ): string {
		return in_array( $verdict, array( self::CORRECT, self::INCORRECT ), true )
			? (string) $verdict
			: self::PENDING;
	}
}
