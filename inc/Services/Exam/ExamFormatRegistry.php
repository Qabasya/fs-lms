<?php
/**
 * Принимает типизированные шкалы через фильтр плагина и выбирает их по виду экзамена.
 *
 * @package FS_LMS
 */

declare( strict_types=1 );

namespace Inc\Services\Exam;

use Inc\DTO\Exam\ExamFormatDTO;
use Inc\Enums\Assessment\AssessmentKind;
use Inc\Enums\Exam\ExamDirection;

/** Принимает типизированные шкалы через фильтр плагина и выбирает их по виду экзамена. */
class ExamFormatRegistry {
	public const string FILTER = 'fs_lms_exam_formats';

	/**
	 * Принимает только ExamFormatDTO из фильтра; последний формат вида заменяет предыдущий.
	 *
	 * @return array<string, ExamFormatDTO>
	 */
	public function all(): array {
		$formats = (array) apply_filters( self::FILTER, array() );
		$result  = array();

		foreach ( $formats as $format ) {
			if ( $format instanceof ExamFormatDTO ) {
				$result[ $format->kind->value ] = $format;
			}
		}

		return $result;
	}

	/**
	 * Шкала выбранного вида экзамена; null, если провайдер её не зарегистрировал.
	 */
	public function for( AssessmentKind $kind ): ?ExamFormatDTO {
		$all = $this->all();
		return $all[ $kind->value ] ?? null;
	}

	/**
	 * Число единиц шкалы; незарегистрированный вид даёт ноль.
	 */
	public function unitCount( AssessmentKind $kind ): int {
		$format = $this->for( $kind );
		return $format->unitCount ?? 0;
	}

	/**
	 * Шкалы направления в порядке регистрации.
	 *
	 * @return list<ExamFormatDTO>
	 */
	public function forDirection( ExamDirection $direction ): array {
		$result = array();

		foreach ( $this->all() as $format ) {
			if ( $format->direction === $direction ) {
				$result[] = $format;
			}
		}

		return $result;
	}
}
