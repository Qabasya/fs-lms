<?php

declare( strict_types=1 );

namespace Inc\Services\Assessment;

use Inc\DTO\Assessment\AssessmentDTO;
use Inc\DTO\Assessment\AttemptDTO;
use Inc\Enums\Assessment\AttemptStatus;

/**
 * Class AttemptOutcomeService
 *
 * Единый источник исхода попытки (сдано/не сдано) и его текстовой метки/состояния.
 * Ключевое отличие от прежнего AttemptDTO::outcomeLabel(): для ЕГЭ/КЕГЭ проходной
 * балл сверяется по ВТОРИЧНОМУ баллу (перевод через SecondaryScoreService), а не по
 * первичному (задача 10). DTO не тянет зависимость на перевод — сравнение живёт здесь.
 *
 * @package Inc\Services\Assessment
 */
class AttemptOutcomeService {

	public function __construct(
		private readonly SecondaryScoreService $secondaryScore,
	) {}

	/**
	 * Балл, по которому сверяется проходной порог: вторичный для ЕГЭ/КЕГЭ
	 * (если таблица перевода покрывает первичный), иначе — первичный.
	 */
	public function comparableScore( AttemptDTO $attempt, AssessmentDTO $assessment ): float {
		$primary = $attempt->totalScore ?? 0.0;

		if ( $assessment->kind->needsSecondaryScore() ) {
			$secondary = $this->secondaryScore->translate( $primary, $assessment->scoreMap );
			if ( null !== $secondary ) {
				return (float) $secondary;
			}
		}

		return $primary;
	}

	/** Пройдена ли попытка (проходной порог достигнут). Только для оценённых попыток. */
	public function passed( AttemptDTO $attempt, AssessmentDTO $assessment ): bool {
		if ( AttemptStatus::Graded !== $attempt->status ) {
			return false;
		}

		$passScore = $assessment->passScore;
		if ( $passScore <= 0 ) {
			return true; // Порог не задан — засчитано (совпадает с прежним поведением).
		}

		return $this->comparableScore( $attempt, $assessment ) >= $passScore;
	}

	/**
	 * Человекочитаемый исход попытки для ученика.
	 * Оценена: «Успешно»/«Неудачно» по {@see passed()}; сдана и ждёт ручной проверки →
	 * «На проверке»; просрочена → «Не сдана»; в процессе → «В процессе».
	 */
	public function label( AttemptDTO $attempt, AssessmentDTO $assessment ): string {
		return match ( $attempt->status ) {
			AttemptStatus::Graded     => $this->passed( $attempt, $assessment ) ? 'Успешно' : 'Неудачно',
			AttemptStatus::Submitted  => 'На проверке',
			AttemptStatus::Expired    => 'Не сдана',
			AttemptStatus::InProgress => 'В процессе',
		};
	}

	/**
	 * Состояние плашки результата: ok (успешно), fail (неуспешно/не сдана),
	 * review (ждёт ручной проверки).
	 */
	public function state( AttemptDTO $attempt, AssessmentDTO $assessment ): string {
		return match ( $attempt->status ) {
			AttemptStatus::Submitted => 'review',
			AttemptStatus::Graded    => $this->passed( $attempt, $assessment ) ? 'ok' : 'fail',
			default                  => 'fail',
		};
	}
}
