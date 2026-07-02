<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\Managers\Assessment\AssessmentManager;
use Inc\Managers\Course\WorkManager;
use Inc\Managers\Wp\MediaManager;
use Inc\Managers\Wp\PostManager;
use Inc\Repositories\WPDBRepositories\AssessmentAnswerRepository;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\SubmissionRepository;
use Inc\Services\Task\CorrectAnswerResolver;

/**
 * Class WorkDetailService
 *
 * Деталь работы для «Сводки по ученику» (Эпик 10 T10.9): по `source_type` из
 * GradebookEntryDTO собирает условия задач, ответ ученика, вердикт и баллы.
 *  - `submission` (source_id = id агрегатной строки сдачи) — «работа».
 *  - `attempt`    (source_id = id попытки ассессмента)      — экзамен.
 *
 * Правильные ответы НЕ отдаются (чекеры возвращают только вердикт; см.
 * ExamPayloadFilter) — показываем условие + ответ ученика + вердикт/баллы.
 * `group_id` в результате — только для проверки доступа в колбэке (удаляется перед отдачей).
 *
 * @package Inc\Services\Course
 */
class WorkDetailService {

	public function __construct(
		private readonly SubmissionRepository        $submissions,
		private readonly WorkManager                 $works,
		private readonly PostManager                 $posts,
		private readonly GroupLessonRepository       $groupLessons,
		private readonly AssessmentAttemptRepository $attempts,
		private readonly AssessmentAnswerRepository  $answers,
		private readonly AssessmentManager           $assessments,
		private readonly CorrectAnswerResolver       $correctAnswers,
		private readonly MediaManager                $media,
	) {}

	/**
	 * @return array<string,mixed>|null  null, если работа не найдена / тип неизвестен
	 */
	public function forWork( string $sourceType, int $sourceId ): ?array {
		return match ( $sourceType ) {
			'submission' => $this->fromSubmission( $sourceId ),
			'attempt'    => $this->fromAttempt( $sourceId ),
			default      => null,
		};
	}

	private function fromSubmission( int $submissionId ): ?array {
		$sub = $this->submissions->find( $submissionId );
		if ( ! $sub ) {
			return null;
		}
		$work    = $this->works->get( $sub->workId );
		$perTask = $this->decode( $sub->answerText );

		// Ответы ученика по каждой задаче (per-task строки сдачи).
		$answerByTask = array();
		foreach ( $this->submissions->listPerTaskByStudentWorkLesson( $sub->studentPersonId, $sub->groupLessonId, $sub->workId ) as $row ) {
			if ( null !== $row->taskId ) {
				$answerByTask[ $row->taskId ] = $row->answerText;
			}
		}

		$itemIds = $work?->itemIds ?: array_map( 'intval', array_keys( $perTask ) );
		$tasks   = array();
		$n       = 0;
		foreach ( $itemIds as $taskId ) {
			$taskId = (int) $taskId;
			$pt     = $perTask[ $taskId ] ?? array();
			$tasks[] = array(
				'n'         => ++$n,
				'condition' => $this->condition( $taskId ),
				'answer'    => (string) ( $answerByTask[ $taskId ] ?? '' ),
				'correct'   => $this->correctAnswers->resolve( $taskId ),
				'verdict'   => (string) ( $pt['verdict'] ?? 'pending' ),
				'score'     => isset( $pt['score'] ) ? (float) $pt['score'] : null,
				'max_score' => isset( $pt['maxScore'] ) ? (float) $pt['maxScore'] : null,
			);
		}

		// Фолбэк: свободный ответ (не разложен по задачам) — единый блок.
		if ( empty( $tasks ) && null !== $sub->answerText && '' !== $sub->answerText ) {
			$tasks[] = array(
				'n'         => 1,
				'condition' => $work?->instructions ? wp_kses_post( $work->instructions ) : '',
				'answer'    => (string) $sub->answerText,
				'correct'   => null,
				'verdict'   => 'pending',
				'score'     => $sub->score,
				'max_score' => $sub->maxScore,
			);
		}

		// T13.1: вложение ученика (фото/файл решения) — форма одиночной сдачи уже
		// принимает файл (SubmissionService::submit → MediaManager::uploadFromRequest),
		// но деталь работы его раньше не отдавала — учитель не мог его увидеть.
		$attachmentUrl  = null;
		$attachmentMime = null;
		if ( $sub->attachmentId ) {
			$attachmentUrl  = $this->media->url( $sub->attachmentId );
			$attachmentMime = get_post_mime_type( $sub->attachmentId ) ?: null;
		}

		return array(
			'kind'            => 'work',
			'title'           => $work?->title ?? 'Работа',
			'status'          => $sub->status->value,
			'score'           => $sub->score,
			'max_score'       => $sub->maxScore,
			'feedback'        => $sub->feedback,
			'gradable'        => true,
			'submission_id'   => $sub->id,
			'tasks'           => $tasks,
			// T12.2 (D13): дедлайн работы (снимок на момент сдачи) + постоянная метка «Просрочено».
			'due_at'          => $sub->dueAt,
			'is_late'         => $sub->isLate(),
			'attachment_url'  => $attachmentUrl,
			'attachment_mime' => $attachmentMime,
			'group_id'        => $this->groupLessons->find( $sub->groupLessonId )?->groupId ?? 0,
		);
	}

	private function fromAttempt( int $attemptId ): ?array {
		$attempt = $this->attempts->find( $attemptId );
		if ( ! $attempt ) {
			return null;
		}
		$assessment = $this->assessments->get( $attempt->assessmentId );

		$tasks = array();
		$n     = 0;
		foreach ( $this->answers->listByAttempt( $attemptId ) as $ans ) {
			$verdict = null === $ans->isCorrect ? 'pending' : ( $ans->isCorrect ? 'correct' : 'incorrect' );
			$tasks[] = array(
				'n'         => ++$n,
				'task_id'   => $ans->taskId,
				'condition' => $this->condition( $ans->taskId ),
				'answer'    => (string) ( $ans->answerText ?? '' ),
				'correct'   => $this->correctAnswers->resolve( $ans->taskId ),
				'verdict'   => $verdict,
				'score'     => $ans->score,
				'max_score' => $ans->maxScore,
			);
		}

		return array(
			'kind'          => 'exam',
			'title'         => $assessment?->title ?? 'Экзамен',
			'status'        => $attempt->status->value,
			'score'         => $attempt->totalScore,
			'max_score'     => $attempt->maxScore,
			'feedback'      => null,
			'gradable'      => false, // целиком не оценивается — грейдинг по задачам (T11.9)
			'submission_id' => null,
			'attempt_id'    => $attemptId,
			'tasks'         => $tasks,
			'group_id'      => $attempt->groupId ?? 0,
		);
	}

	private function condition( int $taskId ): string {
		$post = $this->posts->get( $taskId );
		return $post ? wp_kses_post( $post->post_content ) : '';
	}

	/** @return array<int|string,mixed> */
	private function decode( ?string $json ): array {
		if ( null === $json || '' === $json ) {
			return array();
		}
		$decoded = json_decode( $json, true );
		return is_array( $decoded ) ? $decoded : array();
	}
}
