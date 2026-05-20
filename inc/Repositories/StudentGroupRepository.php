<?php

declare( strict_types=1 );

namespace Inc\Repositories;

use Inc\Contracts\RepositoryInterface;
use Inc\Enums\OptionName;

/**
 * Class StudentGroupRepository
 *
 * Репозиторий для управления группами учащихся.
 *
 * @package Inc\Repositories
 */
class StudentGroupRepository implements RepositoryInterface {

	/**
	 * @inheritDoc
	 */
	public function readAll(): array {
		$groups = get_option( OptionName::STUDENT_GROUPS->value, array() );
		return is_array( $groups ) ? $groups : array();
	}

	/**
	 * @inheritDoc
	 */
	public function update( array $data ): bool {
		if ( ! isset( $data['id'] ) || ! isset( $data['name'] ) || ! isset( $data['year_id'] ) ) {
			return false;
		}

		$groups = $this->readAll();

		$groups[ $data['id'] ] = array(
			'id'          => (string) $data['id'],
			'name'        => (string) $data['name'],
			'year_id'     => (string) $data['year_id'],
			'subject_key' => (string) ( $data['subject_key'] ?? '' ),
		);

		update_option( OptionName::STUDENT_GROUPS->value, $groups );
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function delete( array $data ): bool {
		if ( ! isset( $data['id'] ) ) {
			return false;
		}

		$groups = $this->readAll();

		if ( ! isset( $groups[ $data['id'] ] ) ) {
			return false;
		}

		unset( $groups[ $data['id'] ] );
		update_option( OptionName::STUDENT_GROUPS->value, $groups );
		return true;
	}

	// ============================ КАСТОМНЫЕ МЕТОДЫ ============================ //

	/**
	 * Получает конкретную группу по её уникальному ID.
	 *
	 * @param string $id
	 *
	 * @return array|null
	 */
	public function getById( string $id ): ?array {
		$groups = $this->readAll();
		return $groups[ $id ] ?? null;
	}

	/**
	 * Получает группы, отфильтрованные по году обучения и предмету.
	 * Сюда переехала чистая выборка из бывшего сервиса.
	 *
	 * @param string $year_id
	 * @param string $subject_key
	 *
	 * @return array
	 */
	public function getByYearAndSubject( string $year_id, string $subject_key ): array {
		return array_filter(
			$this->readAll(),
			static function ( array $group ) use ( $year_id, $subject_key ): bool {
				return $year_id === ( $group['year_id'] ?? '' )
				       && $subject_key === ( $group['subject_key'] ?? '' );
			}
		);
	}
}