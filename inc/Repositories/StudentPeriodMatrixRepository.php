<?php

declare(strict_types=1);

namespace Inc\Repositories;

use Inc\DTO\StudentEnrollmentDTO;
use Inc\Enums\OptionName;

class StudentPeriodMatrixRepository {

	public function readAll(): array {
		$matrix = get_option( OptionName::STUDENT_PERIOD_META->value, array() );
		return is_array( $matrix ) ? $matrix : array();
	}

	/** @return StudentEnrollmentDTO[] */
	public function getByPeriod( string $period_id ): array {
		return array_values( array_map(
			fn( array $meta ) => StudentEnrollmentDTO::fromArray( $meta ),
			array_filter(
				$this->readAll(),
				fn( array $meta ) => $period_id === ( $meta['period_id'] ?? '' )
			)
		) );
	}

	public function save( StudentEnrollmentDTO $dto ): bool {
		$matrix                      = $this->readAll();
		$matrix[ $dto->storageKey() ] = $dto->toArray();

		return (bool) update_option( OptionName::STUDENT_PERIOD_META->value, $matrix );
	}

	public function remove( int $student_id, string $period_id ): bool {
		$matrix = $this->readAll();
		$key    = "usr_{$student_id}_{$period_id}";

		if ( ! isset( $matrix[ $key ] ) ) {
			return false;
		}

		unset( $matrix[ $key ] );

		return (bool) update_option( OptionName::STUDENT_PERIOD_META->value, $matrix );
	}
}
