<?php

declare( strict_types=1 );

namespace Inc\Services\Assessment;

use Inc\DTO\Assessment\AssessmentDTO;
use Inc\Managers\Wp\TermManager;
use Inc\Services\Subject\PostTypeResolver;

/**
 * Class ScoringUnits
 *
 * Единицы зачёта работы. В экзамене станции задания с ОДНИМ номером — один балл на всех:
 * три задания №14 в варианте дают столько же первичных баллов, сколько одно (максимум
 * работы по-прежнему 29), и засчитываются, только если верны все три; ошибка в любом —
 * 0 за номер. Задание с уникальным номером ведёт себя как раньше, включая частичный
 * балл за позиции №26/№27.
 *
 * Номер задания: терм `{key}_task_number`, иначе номер, проставленный на самой работе.
 * Группируются задания одного ТИПА — по «живому» номеру ({@see ArchiveTaskNumber}): архивное
 * №110 — тип №10, и 20 заданий №1 плюс по одному на остальные 26 типов дают те же 29
 * первичных баллов, что и обычный КИМ. Задание без номера — своя единица.
 *
 * Правило включает вид работы ({@see \Inc\Enums\Assessment\AssessmentKind::groupsEqualNumbers()}),
 * для остальных видов итог — обычная сумма.
 *
 * @package Inc\Services\Assessment
 */
class ScoringUnits {

	public function __construct(
		private readonly TermManager     $terms,
		private readonly ArchiveTaskNumber $archive,
	) {}

	/**
	 * Ключ единицы зачёта каждого задания работы.
	 *
	 * @return array<int, string> task_id => ключ (одинаковый у заданий одного номера)
	 */
	public function keysFor( AssessmentDTO $assessment ): array {
		$taxonomy = $assessment->subjectKey . PostTypeResolver::TASK_NUMBER_SUFFIX;
		$keys     = array();

		foreach ( $assessment->taskIds as $taskId ) {
			$taskId = (int) $taskId;
			$number = $this->numberOf( $taskId, $taxonomy, $assessment );

			$keys[ $taskId ] = '' !== $number ? 'n:' . $number : 't:' . $taskId;
		}

		return $keys;
	}

	/**
	 * Итог работы по единицам зачёта.
	 *
	 * Балл единицы — её максимум × доля наименее решённого задания: у заданий с одним
	 * слотом это «все верны → полный балл, иначе 0», у одиночного №26 — прежний частичный
	 * балл. Задание на ручной проверке (pending) в доле считается нулём.
	 *
	 * @param AssessmentDTO                                                                  $assessment Работа
	 * @param array<int, array{score: float, max: float, pending?: bool, correct?: bool}> $perTask    task_id => оценка задания
	 *
	 * @return array{score: float, max: float}
	 */
	public function totals( AssessmentDTO $assessment, array $perTask ): array {
		if ( ! $assessment->kind->groupsEqualNumbers() ) {
			return array(
				'score' => array_sum( array_column( $perTask, 'score' ) ),
				'max'   => array_sum( array_column( $perTask, 'max' ) ),
			);
		}

		$keys   = $this->keysFor( $assessment );
		$groups = array();
		foreach ( $perTask as $taskId => $result ) {
			$groups[ $keys[ (int) $taskId ] ?? 't:' . $taskId ][] = $result;
		}

		$score = 0.0;
		$max   = 0.0;
		foreach ( $groups as $members ) {
			$unitMax  = (float) max( array_column( $members, 'max' ) );
			$fraction = 1.0;
			foreach ( $members as $member ) {
				$memberMax = (float) $member['max'];
				$share     = ( $memberMax > 0.0 && empty( $member['pending'] ) )
					? min( 1.0, max( 0.0, (float) $member['score'] / $memberMax ) )
					: 0.0;
				$fraction  = min( $fraction, $share );
			}

			$score += $unitMax * $fraction;
			$max   += $unitMax;
		}

		return array(
			'score' => $score,
			'max'   => $max,
		);
	}

	/** Номер задания как строка: терм таксономии, иначе ручной номер работы ('' — номера нет). */
	private function numberOf( int $taskId, string $taxonomy, AssessmentDTO $assessment ): string {
		$terms = $this->terms->getPostTerms( $taskId, $taxonomy );
		$first = reset( $terms );
		if ( false !== $first && '' !== trim( $first->name ) ) {
			return $this->archive->baseOf( $first->name );
		}

		return $this->archive->baseOf( (string) ( $assessment->taskNumbers[ $taskId ] ?? '' ) );
	}
}
