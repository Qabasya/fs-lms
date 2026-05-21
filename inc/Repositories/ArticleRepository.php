<?php

declare( strict_types=1 );

namespace Inc\Repositories;

class ArticleRepository {

	public function get( int $post_id ): ?\WP_Post {
		$post = get_post( $post_id );
		return $post instanceof \WP_Post ? $post : null;
	}

	public function create( array $data ): int {
		$post = wp_insert_post( $data );
		return is_wp_error( $post ) ? 0 : (int) $post;
	}

	public function update( array $data ): bool {
		if ( empty( $data['ID'] ) ) {
			return false;
		}
		$result = wp_update_post( $data, true );
		return ! is_wp_error( $result );
	}

	public function delete( int $post_id, bool $force = false ): bool {
		return (bool) wp_delete_post( $post_id, $force );
	}

	/** @return \WP_Post[] */
	public function findRelated( string $post_type, int $term_id, string $taxonomy ): array {
		$query = new \WP_Query( array(
			'post_type'      => $post_type,
			'post_status'    => 'publish',
			'posts_per_page' => 4,
			'no_found_rows'  => true,
			'tax_query'      => array(
				array(
					'taxonomy' => $taxonomy,
					'field'    => 'term_id',
					'terms'    => $term_id,
				),
			),
		) );

		return $query->posts;
	}

	/** @return \WP_Post[] */
	public function findRandom( string $post_type ): array {
		$query = new \WP_Query( array(
			'post_type'      => $post_type,
			'post_status'    => 'publish',
			'posts_per_page' => 4,
			'no_found_rows'  => true,
			'orderby'        => 'rand',
		) );

		return $query->posts;
	}
}
