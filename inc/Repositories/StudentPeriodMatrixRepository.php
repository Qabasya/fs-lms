<?php

declare( strict_types=1 );

namespace Inc\Repositories;

use Inc\Contracts\RepositoryInterface;
use Inc\Enums\OptionName;

/**
 * Class StudentPeriodMatrixRepository
 *
 * Репозиторий для работы с таблицей связей учеников, периодов обучения и групп.
 *
 * @package Inc\Repositories
 */
class StudentPeriodMatrixRepository implements RepositoryInterface {

	/**
	 * @inheritDoc
	 */
	public function readAll(): array {
		$matrix = get_option( OptionName::STUDENT_PERIOD_META->value, array() );
		return is_array( $matrix ) ? $matrix : array();
	}

	/**
	 * @inheritDoc
	 */
	public function update( array $data ): bool {
		if ( ! isset( $data['student_id'] ) || ! isset( $data['period_id'] ) ) {
			return false;
		}

		$matrix = $this->readAll();
		$key    = "usr_{$data['student_id']}_{$data['period_id']}";

		$matrix[ $key ] = array(
			'student_id' => (int) $data['student_id'],
			'period_id'  => (string) $data['period_id'],
			'class_num'  => (int) ( $data['class_num'] ?? 0 ),
			'group_id'   => (string) ( $data['group_id'] ?? '' ),
		);

		update_option( OptionName::STUDENT_PERIOD_META->value, $matrix );
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function delete( array $data ): bool {
		if ( ! isset( $data['student_id'] ) || ! isset( $data['period_id'] ) ) {
			return false;
		}

		$matrix = $this->readAll();
		$key    = "usr_{$data['student_id']}_{$data['period_id']}";

		if ( ! isset( $matrix[ $key ] ) ) {
			return false;
		}

		unset( $matrix[ $key ] );
		update_option( OptionName::STUDENT_PERIOD_META->value, $matrix );
		return true;
	}

	// ============================ КАСТОМНЫЕ МЕТОДЫ ============================ //

	/**
	 * Возвращает все записи матрицы, привязанные к конкретному учебному периоду.
	 *
	 * @param string $period_id
	 *
	 * @return array
	 */
	public function getByPeriod( string $period_id ): array {
		return array_filter(
			$this->readAll(),
			static function ( array $meta ) use ( $period_id ): bool {
				return $period_id === ( $meta['period_id'] ?? '' );
			}
		);
	}
}
