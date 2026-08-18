<?php

declare( strict_types=1 );

namespace Inc\Services\Assessment;

use Inc\DTO\Assessment\AssessmentDTO;
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
		private readonly AutoGradeService            $autoGrade,
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

		$rows = $this->answers->listByAttempt( $attemptId );

		// ID заданий приходят из таблицы ответов, не из WP_Query — прогреваем
		// мета-кэш одним запросом вместо запроса на каждый getMeta() в цикле.
		$this->posts->primeMetaCache( array_map( static fn( $a ) => $a->taskId, $rows ) );

		$result = array();
		$n      = 0;
		foreach ( $rows as $ans ) {
			$verdict = null === $ans->isCorrect ? 'pending' : ( $ans->isCorrect ? 'correct' : 'incorrect' );

			$template = TaskTemplate::fromDatabase(
				(string) $this->posts->getMeta( $ans->taskId, PostMetaName::TemplateType->value )
			);

			$result[] = array(
				'n'         => ++$n,
				'task_id'   => $ans->taskId,
				'verdict'   => $verdict,
				'score'     => $ans->score,
				'max_score' => $ans->maxScore,
				'criteria'  => $this->criteriaFor( $ans->taskId, $ans->criteriaScores ),
				'files'     => $this->filesFor( $template, $ans->answerText ),
			);
		}
		return $result;
	}

	/**
	 * Тот же формат строки, что и studentPerTask(), но по ответам, которых нет в
	 * БД, — предпросмотр générique-контрольной (T-preview-4): у предпросмотра нет
	 * ни ученика, ни попытки, страница не пишет ни одной строки в assessment_answers
	 * (аналог KegeResultSheetService::buildFromAnswers() для станции КЕГЭ). Оценка —
	 * тем же алгоритмом, что и настоящая сдача ({@see AutoGradeService::evaluate()}).
	 *
	 * @param AssessmentDTO            $assessment Контрольная
	 * @param array<int, string|array> $rawByTask  Ответ ученика по task_id — как он лёг бы в answer_text
	 *
	 * @return list<array{n: int, task_id: int, verdict: string, score: ?float, max_score: ?float, criteria: list<array{label: string, max_points: float, awarded: ?float}>, files: list<array{url: string, name: string, mime: string}>}>
	 */
	public function previewPerTask( AssessmentDTO $assessment, array $rawByTask ): array {
		$this->posts->primeMetaCache( array_map( 'intval', $assessment->taskIds ) );

		$evaluated = $this->autoGrade->evaluate( $assessment, $assessment->taskIds, $rawByTask )['perTask'];

		$result = array();
		$n      = 0;
		foreach ( $assessment->taskIds as $taskId ) {
			$taskId = (int) $taskId;
			$a      = $evaluated[ $taskId ] ?? null;
			if ( null === $a ) {
				continue; // Задание удалено из банка (см. AutoGradeService::evaluate()).
			}

			$verdict = $a['pending'] ? 'pending' : ( $a['correct'] ? 'correct' : 'incorrect' );

			$template = TaskTemplate::fromDatabase(
				(string) $this->posts->getMeta( $taskId, PostMetaName::TemplateType->value )
			);

			$result[] = array(
				'n'         => ++$n,
				'task_id'   => $taskId,
				'verdict'   => $verdict,
				'score'     => $a['pending'] ? null : $a['score'],
				'max_score' => $a['max'],
				// Предпросмотр не проставляет баллы по критериям построчно (нет
				// ручной проверки преподавателем) — только их состав и максимум.
				'criteria'  => $this->criteriaFor( $taskId, null ),
				'files'     => $this->filesFor( $template, $rawByTask[ $taskId ] ?? '' ),
			);
		}
		return $result;
	}

	/** @return list<array{url: string, name: string, mime: string}> */
	private function filesFor( TaskTemplate $template, mixed $answerText ): array {
		if ( TaskTemplate::FileAnswer !== $template ) {
			return array();
		}

		$decoded = is_string( $answerText ) && '' !== $answerText
			? json_decode( $answerText, true )
			: ( is_array( $answerText ) ? $answerText : null );
		$ids     = is_array( $decoded ) && is_array( $decoded['files'] ?? null ) ? $decoded['files'] : array();

		$files = array();
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
		return $files;
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
