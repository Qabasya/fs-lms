<?php declare( strict_types=1 );

namespace Inc\Services\Exam;

use Inc\DTO\Exam\ExamFormatDTO;
use Inc\Enums\Assessment\AssessmentKind;
use Inc\Enums\Exam\ExamDirection;

class ExamFormatRegistry {
	public const string FILTER = 'fs_lms_exam_formats';

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

	public function for( AssessmentKind $kind ): ?ExamFormatDTO {
		$all = $this->all();
		return $all[ $kind->value ] ?? null;
	}

	public function unitCount( AssessmentKind $kind ): int {
		$format = $this->for( $kind );
		return $format?->unitCount ?? 0;
	}

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
