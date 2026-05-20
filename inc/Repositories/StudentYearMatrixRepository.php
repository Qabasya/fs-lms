<?php

declare( strict_types=1 );

namespace Inc\Repositories;

use Inc\Contracts\RepositoryInterface;
use Inc\Enums\OptionName;

/**
 * Class StudentYearMatrixRepository
 *
 * Репозиторий для работы с таблицей связей учеников, годов обучения и групп.
 *
 * @package Inc\Repositories
 */
class StudentYearMatrixRepository implements RepositoryInterface {

	/**
	 * @inheritDoc
	 */
	public function readAll(): array {
		$matrix = get_option( OptionName::STUDENT_YEAR_META->value, array() );
		return is_array( $matrix ) ? $matrix : array();
	}

	/**
	 * @inheritDoc
	 */
	public function update( array $data ): bool {
		if ( ! isset( $data['student_id'] ) || ! isset( $data['year_id'] ) ) {
			return false;
		}

		$matrix = $this->readAll();
		$key    = "usr_{$data['student_id']}_{$data['year_id']}";

		$matrix[ $key ] = array(
			'student_id' => (int) $data['student_id'],
			'year_id'    => (string) $data['year_id'],
			'class_num'  => (int) ( $data['class_num'] ?? 0 ),
			'group_id'   => (string) ( $data['group_id'] ?? '' ),
		);

		update_option( OptionName::STUDENT_YEAR_META->value, $matrix );
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function delete( array $data ): bool {
		if ( ! isset( $data['student_id'] ) || ! isset( $data['year_id'] ) ) {
			return false;
		}

		$matrix = $this->readAll();
		$key    = "usr_{$data['student_id']}_{$data['year_id']}";

		if ( ! isset( $matrix[ $key ] ) ) {
			return false;
		}

		unset( $matrix[ $key ] );
		update_option( OptionName::STUDENT_YEAR_META->value, $matrix );
		return true;
	}

	// ============================ КАСТОМНЫЕ МЕТОДЫ ============================ //

	/**
	 * Возвращает все записи матрицы, привязанные к конкретному учебному году.
	 *
	 * @param string $year_id
	 *
	 * @return array
	 */
	public function getByYear( string $year_id ): array {
		return array_filter(
			$this->readAll(),
			static function ( array $meta ) use ( $year_id ): bool {
				return $year_id === ( $meta['year_id'] ?? '' );
			}
		);
	}
}