<?php
declare(strict_types=1);

namespace Inc\Shared\Traits;

trait TaxonomySeeder {

	public function seedTaskNumbers( string $taxonomy, int $count, string $prefix ): void {
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'fields'     => 'ids',
			)
		);

		foreach ( $terms as $term_id ) {
			wp_delete_term( $term_id, $taxonomy );
		}

		for ( $i = 1; $i <= $count; $i++ ) {
			$this->ensureSeederTerm(
				(string) $i,
				$taxonomy,
				array(
					'description' => "Задание №{$i}",
					'slug'        => "{$prefix}_{$i}",
				)
			);
		}
	}

	private function ensureSeederTerm( string $name, string $taxonomy, array $args = array() ): void {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			register_taxonomy( $taxonomy, array() );
		}

		if ( ! taxonomy_exists( $taxonomy ) ) {
			return;
		}

		if ( ! term_exists( $name, $taxonomy ) ) {
			wp_insert_term( $name, $taxonomy, $args );
		}
	}
}