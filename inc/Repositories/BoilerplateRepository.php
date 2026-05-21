<?php

declare(strict_types=1);

namespace Inc\Repositories;

use Inc\DTO\TaskTypeBoilerplateDTO;
use Inc\Enums\OptionName;

class BoilerplateRepository {

	private string $option_name = OptionName::BOILERPLATE->value;

	private function getRaw(): array {
		$data = get_option( $this->option_name, array() );
		return is_array( $data ) ? $data : array();
	}

	private function hydrateDTO( array $item, string $subject_key, string $term_slug ): TaskTypeBoilerplateDTO {
		return new TaskTypeBoilerplateDTO(
			uid:         $item['uid'],
			subject_key: $subject_key,
			term_slug:   $term_slug,
			title:       $item['title'] ?? 'Без названия',
			content:     $item['content'] ?? '',
			is_default:  $item['is_default'] ?? false
		);
	}

	/** @return TaskTypeBoilerplateDTO[] */
	public function readAll(): array {
		$flat = array();

		foreach ( $this->getRaw() as $subject_key => $terms ) {
			foreach ( $terms as $term_slug => $list ) {
				foreach ( $list as $item ) {
					$flat[] = $this->hydrateDTO( $item, $subject_key, $term_slug );
				}
			}
		}

		return $flat;
	}

	/** @return TaskTypeBoilerplateDTO[] */
	public function getBoilerplates( string $subject_key, string $term_slug ): array {
		$raw_list = $this->getRaw()[ $subject_key ][ $term_slug ] ?? array();

		return array_map(
			fn( array $item ) => $this->hydrateDTO( $item, $subject_key, $term_slug ),
			$raw_list
		);
	}

	public function getDefaultBoilerplate( string $subject_key, string $term_slug ): ?TaskTypeBoilerplateDTO {
		$list = $this->getBoilerplates( $subject_key, $term_slug );

		foreach ( $list as $bp ) {
			if ( $bp->is_default ) {
				return $bp;
			}
		}

		return $list[0] ?? null;
	}

	public function findBoilerplate( string $subject_key, string $term_slug, string $uid ): ?TaskTypeBoilerplateDTO {
		$raw_list = $this->getRaw()[ $subject_key ][ $term_slug ] ?? array();

		foreach ( $raw_list as $item ) {
			if ( isset( $item['uid'] ) && $item['uid'] === $uid ) {
				return $this->hydrateDTO( $item, $subject_key, $term_slug );
			}
		}

		return null;
	}

	public function save( TaskTypeBoilerplateDTO $dto ): bool {
		$all  = $this->getRaw();
		$list = &$all[ $dto->subject_key ][ $dto->term_slug ];

		if ( ! isset( $list ) || ! is_array( $list ) ) {
			$list = array();
		}

		$found = false;

		foreach ( $list as &$item ) {
			if ( $item['uid'] === $dto->uid ) {
				$item  = $dto->toArray();
				$found = true;
			} elseif ( $dto->is_default ) {
				$item['is_default'] = false;
			}
		}
		unset( $item );

		if ( ! $found ) {
			$list[] = $dto->toArray();
		}

		return update_option( $this->option_name, $all );
	}

	public function remove( string $subject_key, string $term_slug, string $uid ): bool {
		$all = $this->getRaw();

		if ( ! isset( $all[ $subject_key ][ $term_slug ] ) || ! is_array( $all[ $subject_key ][ $term_slug ] ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( "FS LMS: Path not found in database: [$subject_key][$term_slug]" );
			}
			return false;
		}

		$found = false;

		foreach ( $all[ $subject_key ][ $term_slug ] as $index => $item ) {
			if ( isset( $item['uid'] ) && $item['uid'] === $uid ) {
				unset( $all[ $subject_key ][ $term_slug ][ $index ] );
				$found = true;
				break;
			}
		}

		if ( ! $found ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( "FS LMS: UID $uid not found inside [$subject_key][$term_slug]" );
			}
			return false;
		}

		$all[ $subject_key ][ $term_slug ] = array_values( $all[ $subject_key ][ $term_slug ] );

		if ( empty( $all[ $subject_key ][ $term_slug ] ) ) {
			unset( $all[ $subject_key ][ $term_slug ] );
		}

		if ( empty( $all[ $subject_key ] ) ) {
			unset( $all[ $subject_key ] );
		}

		return update_option( $this->option_name, $all );
	}

	public function removeBySubject( string $subject_key ): bool {
		$all = $this->getRaw();

		if ( ! isset( $all[ $subject_key ] ) ) {
			return true;
		}

		unset( $all[ $subject_key ] );

		return update_option( $this->option_name, $all );
	}
}
