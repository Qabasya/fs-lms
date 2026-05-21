<?php

declare(strict_types=1);

namespace Inc\Repositories;

use Inc\DTO\TaxonomyDataDTO;
use Inc\Enums\OptionName;

class TaxonomyRepository {

	private string $option_name = OptionName::TAXONOMY->value;

	private function getRaw(): array {
		$all = get_option( $this->option_name, array() );
		return is_array( $all ) ? $all : array();
	}

	/** @return array<string, TaxonomyDataDTO[]> */
	public function readAll(): array {
		$result = array();

		foreach ( $this->getRaw() as $subject_key => $taxonomies ) {
			$result[ $subject_key ] = array();
			foreach ( $taxonomies as $slug => $data ) {
				$result[ $subject_key ][] = TaxonomyDataDTO::fromArray( $slug, $data, $subject_key );
			}
		}

		return $result;
	}

	/** @return TaxonomyDataDTO[] */
	public function getBySubject( string $subject_key ): array {
		$subject_taxes = $this->getRaw()[ $subject_key ] ?? array();
		$result        = array();

		foreach ( $subject_taxes as $slug => $data ) {
			$result[] = TaxonomyDataDTO::fromArray( $slug, $data, $subject_key );
		}

		return $result;
	}

	public function save( TaxonomyDataDTO $dto ): bool {
		$all = $this->getRaw();

		if ( ! isset( $all[ $dto->subject_key ] ) ) {
			$all[ $dto->subject_key ] = array();
		}

		$all[ $dto->subject_key ][ $dto->slug ] = array(
			'name'         => sanitize_text_field( $dto->name ),
			'display_type' => sanitize_text_field( $dto->display_type ),
			'is_required'  => (bool) $dto->is_required,
		);

		return update_option( $this->option_name, $all );
	}

	public function remove( string $subject_key, string $tax_slug ): bool {
		$all = $this->getRaw();

		if ( ! isset( $all[ $subject_key ][ $tax_slug ] ) ) {
			return false;
		}

		unset( $all[ $subject_key ][ $tax_slug ] );

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

	public function clear(): bool {
		return delete_option( $this->option_name );
	}
}
