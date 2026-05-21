<?php

declare(strict_types=1);

namespace Inc\Repositories;

use Inc\DTO\SubjectDTO;
use Inc\Enums\OptionName;

class SubjectRepository {

	private string $option_name = OptionName::SUBJECTS->value;

	private function getRaw(): array {
		$subjects = get_option( $this->option_name, array() );
		return is_array( $subjects ) ? $subjects : array();
	}

	/** @return SubjectDTO[] */
	public function readAll(): array {
		return array_map(
			fn( $item ) => new SubjectDTO( $item['key'], $item['name'] ),
			$this->getRaw()
		);
	}

	public function getByKey( string $key ): ?SubjectDTO {
		$raw = $this->getRaw();
		if ( ! isset( $raw[ $key ] ) ) {
			return null;
		}
		return new SubjectDTO( $raw[ $key ]['key'], $raw[ $key ]['name'] );
	}

	public function save( SubjectDTO $dto ): bool {
		$key  = sanitize_title( $dto->key );
		$name = sanitize_text_field( $dto->name );

		if ( empty( $key ) || empty( $name ) ) {
			return false;
		}

		$subjects          = $this->getRaw();
		$subjects[ $key ]  = array( 'key' => $key, 'name' => $name );

		return update_option( $this->option_name, $subjects );
	}

	public function remove( string $key ): bool {
		$subjects = $this->getRaw();

		if ( ! isset( $subjects[ $key ] ) ) {
			return false;
		}

		unset( $subjects[ $key ] );

		return update_option( $this->option_name, $subjects );
	}

	public function clear(): bool {
		return delete_option( $this->option_name );
	}
}
