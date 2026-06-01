<?php

declare( strict_types=1 );

namespace Inc\Repositories\OptionsRepositories;

use Inc\Enums\OptionName;

class StudentGroupMatrixRepository {

	public function readAll(): array {
		$matrix = get_option( OptionName::StudentGroupMatrix->value, array() );

		return is_array( $matrix ) ? $matrix : array();
	}

	/**
	 * @return int[]
	 */
	public function getStudentsByGroup( string $group_id ): array {
		return $this->readAll()[ $group_id ] ?? array();
	}

	/**
	 * @return string[]
	 */
	public function getGroupsByStudent( int $user_id ): array {
		$result = array();

		foreach ( $this->readAll() as $group_id => $user_ids ) {
			if ( in_array( $user_id, $user_ids, true ) ) {
				$result[] = $group_id;
			}
		}

		return $result;
	}

	public function addStudent( string $group_id, int $user_id ): bool {
		$matrix = $this->readAll();

		if ( ! isset( $matrix[ $group_id ] ) ) {
			$matrix[ $group_id ] = array();
		}

		if ( in_array( $user_id, $matrix[ $group_id ], true ) ) {
			return true;
		}

		$matrix[ $group_id ][] = $user_id;

		return (bool) update_option( OptionName::StudentGroupMatrix->value, $matrix );
	}

	public function removeStudent( string $group_id, int $user_id ): bool {
		$matrix = $this->readAll();

		if ( ! isset( $matrix[ $group_id ] ) ) {
			return false;
		}

		$matrix[ $group_id ] = array_values(
			array_filter( $matrix[ $group_id ], fn( int $id ) => $id !== $user_id )
		);

		return (bool) update_option( OptionName::StudentGroupMatrix->value, $matrix );
	}

	public function removeGroup( string $group_id ): void {
		$matrix = $this->readAll();

		if ( ! array_key_exists( $group_id, $matrix ) ) {
			return;
		}

		unset( $matrix[ $group_id ] );

		update_option( OptionName::StudentGroupMatrix->value, $matrix );
	}

	/**
	 * @param string[] $group_ids
	 */
	public function removeGroups( array $group_ids ): void {
		if ( empty( $group_ids ) ) {
			return;
		}

		$matrix = $this->readAll();

		foreach ( $group_ids as $group_id ) {
			unset( $matrix[ $group_id ] );
		}

		update_option( OptionName::StudentGroupMatrix->value, $matrix );
	}
}
