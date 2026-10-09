<?php

declare( strict_types=1 );

namespace Inc\Services\Exam;

use Inc\DTO\Assessment\AttemptDTO;
use Inc\DTO\Exam\ExamEventDTO;
use Inc\Enums\Assessment\AssessmentKind;
use Inc\Enums\Exam\ExamDirection;
use Inc\Managers\Assessment\AssessmentManager;
use Inc\Repositories\WPDBRepositories\AssessmentAnswerRepository;
use Inc\Services\Assessment\ScoringUnits;
use Inc\Services\Assessment\SecondaryScoreService;

/**
 * Баллы КЕГЭ и ОГЭ (7.3). Клиент получает готовые числа и ничего не считает сам.
 *
 * Шкала и максимум берутся **из снимка проведения** — история не пересчитывается при смене
 * конфигурации модуля; формат модуля — запасной путь, когда снимка нет.
 */
class ExamScoreService {

	/** Худший статус единицы: чем больше число, тем «хуже». */
	private const STATUS_SEVERITY = array(
		'pending'    => 4,
		'unanswered' => 3,
		'incorrect'  => 3,
		'partial'    => 2,
		'correct'    => 1,
	);

	public function __construct(
		private readonly ExamFormatRegistry $formats,
		private readonly AssessmentManager $assessments,
		private readonly AssessmentAnswerRepository $answers,
		private readonly ScoringUnits $scoringUnits,
		private readonly SecondaryScoreService $secondaryScores,
	) {}

	/**
	 * Итог попытки.
	 *
	 * @return array{
	 *   direction: string, primary: int, primary_max: int, secondary: ?int, secondary_max: ?int,
	 *   grade: ?int, grade_max: ?int, pending: bool, final: bool
	 * }
	 */
	public function summarize( AttemptDTO $attempt, ExamEventDTO $event ): array {
		$snapshot   = $event->snapshotFor( $attempt->assessmentId );
		$assessment = $this->assessments->get( $attempt->assessmentId );
		$kind       = AssessmentKind::tryFrom( (string) ( $snapshot['kind'] ?? '' ) ) ?? $assessment?->kind;
		$format     = null !== $kind ? $this->formats->for( $kind ) : null;

		// Направление — свойство формата, а не конкретного модуля; без формата (модуль выключен) проведений нет.
		$direction = $format->direction ?? ExamDirection::Ege;
		$scale     = (array) ( $snapshot['scale'] ?? $format->scale ?? array() );
		$primary   = (int) round( $attempt->totalScore ?? 0.0 );
		$pending   = $this->answers->hasPendingAnswers( $attempt->id );

		// Неподтверждённый итог окончательным не показывается: вторичный балл и отметка — только без ручной части.
		$converted = $pending ? null : $this->secondaryScores->translate( (float) $primary, $scale );
		$isEge     = ExamDirection::Ege === $direction;

		return array(
			'direction'     => $direction->value,
			'primary'       => $primary,
			'primary_max'   => (int) ( $snapshot['primary_max'] ?? $format->primaryMax ?? round( $attempt->maxScore ?? 0.0 ) ),
			'secondary'     => $isEge ? $converted : null,
			'secondary_max' => $isEge ? ( isset( $snapshot['secondary_max'] ) ? (int) $snapshot['secondary_max'] : $format?->secondaryMax ) : null,
			'grade'         => $isEge ? null : $converted,
			'grade_max'     => $isEge ? null : ( $format?->gradeMax ?: 5 ),
			'pending'       => $pending,
			'final'         => ! $pending,
		);
	}

	/**
	 * Подпись итога: ЕГЭ — «{вторичный} из {макс}», ОГЭ — «{первичный} из {макс}, отметка {N}». Неокончательный итог (ручная часть
	 * ещё проверяется) — первичным баллом. Единственное место формата: кабинет (`resultCaption()` в JS) и страница гостя совпадают.
	 *
	 * @param array<string, mixed> $summary Результат {@see summarize()}.
	 */
	public function caption( array $summary ): string {
		if ( array() === $summary ) {
			return '';
		}

		$primary = sprintf( '%s из %s', $summary['primary'], $summary['primary_max'] );
		$final   = ! empty( $summary['final'] );
		if ( ExamDirection::Oge->value === ( $summary['direction'] ?? '' ) ) {
			return $final && null !== ( $summary['grade'] ?? null ) ? sprintf( '%s, отметка %s', $primary, $summary['grade'] ) : $primary;
		}

		return $final && null !== ( $summary['secondary'] ?? null ) ? sprintf( '%s из %s', $summary['secondary'], $summary['secondary_max'] ) : $primary;
	}

	/**
	 * Единицы оценивания для перечня заданий: несколько заданий одного номера — одна единица.
	 *
	 * @param array<int, array<string, mixed>> $tasks Задания из WorkDetailService (`unit_key`, `number`, `anchor`, `verdict`, `score`, `max_score`, `task_id`).
	 *
	 * @return list<array{unit_key: string, number: string, score: float, max: float, status: string, anchor: string}>
	 */
	public function units( AttemptDTO $attempt, array $tasks ): array {
		$assessment = $this->assessments->get( $attempt->assessmentId );

		$groups = array();
		foreach ( $tasks as $task ) {
			$groups[ (string) ( $task['unit_key'] ?? 't:' . ( $task['task_id'] ?? 0 ) ) ][] = $task;
		}

		$units = array();
		foreach ( $groups as $unitKey => $members ) {
			$totals = $this->unitTotals( $assessment, $members );

			$status = 'correct';
			foreach ( $members as $member ) {
				$verdict = (string) ( $member['verdict'] ?? 'pending' );
				if ( ( self::STATUS_SEVERITY[ $verdict ] ?? 0 ) > ( self::STATUS_SEVERITY[ $status ] ?? 0 ) ) {
					$status = $verdict;
				}
			}

			$units[] = array(
				'unit_key' => $unitKey,
				'number'   => (string) ( $members[0]['number'] ?? '' ),
				'score'    => (float) $totals['score'],
				'max'      => (float) $totals['max'],
				'status'   => $status,
				'anchor'   => (string) ( $members[0]['anchor'] ?? '' ),
			);
		}

		return $units;
	}

	/**
	 * Балл единицы — по тем же правилам, что и итог попытки (`ScoringUnits::totals()`), а не суммой строк.
	 *
	 * @param array<int, array<string, mixed>> $members
	 *
	 * @return array{score: float|int, max: float|int}
	 */
	private function unitTotals( ?\Inc\DTO\Assessment\AssessmentDTO $assessment, array $members ): array {
		if ( null === $assessment ) {
			return array(
				'score' => array_sum( array_map( static fn ( array $m ): float => (float) ( $m['score'] ?? 0.0 ), $members ) ),
				'max'   => array_sum( array_map( static fn ( array $m ): float => (float) ( $m['max_score'] ?? 0.0 ), $members ) ),
			);
		}

		$perTask = array();
		foreach ( $members as $member ) {
			$perTask[ (int) ( $member['task_id'] ?? 0 ) ] = array(
				'score'   => (float) ( $member['score'] ?? 0.0 ),
				'max'     => (float) ( $member['max_score'] ?? 0.0 ),
				'pending' => 'pending' === ( $member['verdict'] ?? '' ),
			);
		}

		return $this->scoringUnits->totals( $assessment, $perTask );
	}
}
