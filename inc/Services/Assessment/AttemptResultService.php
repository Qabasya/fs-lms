<?php

declare( strict_types=1 );

namespace Inc\Services\Assessment;

use Inc\Enums\Subject\TaskTemplate;
use Inc\Enums\Wp\PostMetaName;
use Inc\Managers\Wp\MediaManager;
use Inc\Managers\Wp\PostManager;
use Inc\Repositories\WPDBRepositories\AssessmentAnswerRepository;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;

/**
 * Per-task результат попытки для ученика (T13.7).
 * Эталонные ответы не включаются — только ответ ученика, вердикт и критерии.
 */
class AttemptResultService {

	public function __construct(
		private readonly AssessmentAttemptRepository $attempts,
		private readonly AssessmentAnswerRepository  $answers,
		private readonly PostManager                 $posts,
		private readonly MediaManager                $media,
	) {}

	/**
	 * Per-task результат для ученика: вердикт, баллы, критерии, загруженные файлы.
	 *
	 * @return list<array{n: int, task_id: int, verdict: string, score: ?float, max_score: ?float, criteria: list<array{label: string, max_points: float, awarded: ?float}>, files: list<array{url: string, name: string, mime: string}>}>
	 * @throws \InvalidArgumentException Если попытка не найдена или не принадлежит студенту.
	 */
	public function studentPerTask( int $attemptId, int $studentPersonId ): array {
		$attempt = $this->attempts->find( $attemptId );
		if ( ! $attempt || $attempt->studentPersonId !== $studentPersonId ) {
			throw new \InvalidArgumentException( 'Попытка не найдена.' );
		}

		$result = array();
		$n      = 0;
		foreach ( $this->answers->listByAttempt( $attemptId ) as $ans ) {
			$verdict = null === $ans->isCorrect ? 'pending' : ( $ans->isCorrect ? 'correct' : 'incorrect' );

			$template = TaskTemplate::fromDatabase(
				(string) $this->posts->getMeta( $ans->taskId, PostMetaName::TemplateType->value )
			);

			$files = array();
			if ( TaskTemplate::FileAnswer === $template ) {
				$decoded = is_string( $ans->answerText ) && '' !== $ans->answerText
					? json_decode( $ans->answerText, true ) : null;
				$ids     = is_array( $decoded ) && is_array( $decoded['files'] ?? null ) ? $decoded['files'] : array();
				foreach ( $ids as $attachmentId ) {
					$attachmentId = (int) $attachmentId;
					$url          = $attachmentId ? $this->media->url( $attachmentId ) : null;
					if ( ! $url ) {
						continue;
					}
					$files[] = array(
						'url'  => $url,
						'name' => get_the_title( $attachmentId ) ?: "Файл #{$attachmentId}",
						'mime' => get_post_mime_type( $attachmentId ) ?: '',
					);
				}
			}

			$result[] = array(
				'n'         => ++$n,
				'task_id'   => $ans->taskId,
				'verdict'   => $verdict,
				'score'     => $ans->score,
				'max_score' => $ans->maxScore,
				'criteria'  => $this->criteriaFor( $ans->taskId, $ans->criteriaScores ),
				'files'     => $files,
			);
		}
		return $result;
	}

	/** @return list<array{label: string, max_points: float, awarded: ?float}> */
	private function criteriaFor( int $taskId, ?array $criteriaScores ): array {
		$meta = $this->posts->getMeta( $taskId, PostMetaName::Meta->value );
		$defs = is_array( $meta ) && is_array( $meta['task_criteria']['criteria'] ?? null )
			? $meta['task_criteria']['criteria'] : array();
		if ( empty( $defs ) ) {
			return array();
		}

		$out = array();
		foreach ( $defs as $i => $def ) {
			$out[] = array(
				'label'      => (string) ( $def['label'] ?? '' ),
				'max_points' => (float) ( $def['max_points'] ?? 0 ),
				'awarded'    => isset( $criteriaScores[ $i ] ) ? (float) $criteriaScores[ $i ] : null,
			);
		}
		return $out;
	}
}
