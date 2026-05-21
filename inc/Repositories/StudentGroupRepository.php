<?php

declare(strict_types=1);

namespace Inc\Repositories;

use Inc\DTO\StudentGroupDTO;
use Inc\Enums\OptionName;

class StudentGroupRepository {

	public function readAll(): array {
		$groups = get_option( OptionName::STUDENT_GROUPS->value, array() );
		return is_array( $groups ) ? $groups : array();
	}

	public function getById( string $id ): ?StudentGroupDTO {
		$data = $this->readAll()[ $id ] ?? null;
		return $data ? StudentGroupDTO::fromArray( $data ) : null;
	}

	/** @return StudentGroupDTO[] */
	public function getByPeriodAndSubject( string $period_id, string $subject_key ): array {
		return array_values( array_map(
			fn( array $g ) => StudentGroupDTO::fromArray( $g ),
			array_filter(
				$this->readAll(),
				fn( array $g ) => $period_id === ( $g['period_id'] ?? '' )
				                  && $subject_key === ( $g['subject_key'] ?? '' )
			)
		) );
	}

	public function save( StudentGroupDTO $dto ): bool {
		$groups           = $this->readAll();
		$groups[ $dto->id ] = $dto->toArray();

		return (bool) update_option( OptionName::STUDENT_GROUPS->value, $groups );
	}

	public function remove( string $id ): bool {
		$groups = $this->readAll();

		if ( ! isset( $groups[ $id ] ) ) {
			return false;
		}

		unset( $groups[ $id ] );

		return (bool) update_option( OptionName::STUDENT_GROUPS->value, $groups );
	}
}
