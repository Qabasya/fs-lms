<?php

declare( strict_types=1 );

namespace Inc\Services;

use Inc\Managers\PostManager;
use Inc\Managers\TermManager;
use Inc\Repositories\BoilerplateRepository;
use Inc\Repositories\MetaBoxRepository;
use Inc\Repositories\TaxonomyRepository;

class SubjectExportService {

	public function __construct(
		private readonly TaxonomyRepository   $taxonomies,
		private readonly MetaBoxRepository    $metaboxes,
		private readonly BoilerplateRepository $boilerplates,
		private readonly TermManager          $terms,
		private readonly PostManager          $posts,
	) {}

	public function export( string $subject_key ): array {
		return array(
			'taxonomies'   => $this->exportTaxonomies( $subject_key ),
			'metaboxes'    => $this->exportMetaboxes( $subject_key ),
			'boilerplates' => $this->exportBoilerplates( $subject_key ),
			'terms'        => $this->collectTerms( $subject_key ),
			'posts'        => $this->collectPosts( $subject_key ),
		);
	}

	private function exportTaxonomies( string $subject_key ): array {
		$result = array();
		foreach ( $this->taxonomies->getBySubject( $subject_key ) as $dto ) {
			$result[ $dto->slug ] = array(
				'name'         => $dto->name,
				'display_type' => $dto->display_type,
				'is_required'  => $dto->is_required,
			);
		}
		return $result;
	}

	private function exportMetaboxes( string $subject_key ): array {
		$result = array();
		foreach ( $this->metaboxes->readAll() as $dto ) {
			if ( $dto->subject_key === $subject_key ) {
				$result[ $dto->task_number ] = $dto->template_id;
			}
		}
		return $result;
	}

	private function exportBoilerplates( string $subject_key ): array {
		$result = array();
		foreach ( $this->boilerplates->readAll() as $dto ) {
			if ( $dto->subject_key === $subject_key ) {
				$result[ $dto->term_slug ][] = array(
					'uid'        => $dto->uid,
					'title'      => $dto->title,
					'content'    => $dto->content,
					'is_default' => $dto->is_default,
				);
			}
		}
		return $result;
	}

	private function collectTerms( string $subject_key ): array {
		$slugs = array_merge(
			array( "{$subject_key}_task_number" ),
			array_map( fn( $dto ) => $dto->slug, $this->taxonomies->getBySubject( $subject_key ) )
		);

		$result = array();

		foreach ( $slugs as $tax_slug ) {
			$result[ $tax_slug ] = array_map(
				fn( $t ) => array(
					'name'        => $t->name,
					'slug'        => $t->slug,
					'description' => $t->description,
					'parent'      => $t->parent,
				),
				$this->terms->getAll( $tax_slug )
			);
		}

		return $result;
	}

	private function collectPosts( string $subject_key ): array {
		$tax_slugs = array_merge(
			array( "{$subject_key}_task_number" ),
			array_map( fn( $dto ) => $dto->slug, $this->taxonomies->getBySubject( $subject_key ) )
		);

		$result = array();

		foreach ( array( PostTypeResolver::tasks( $subject_key ), PostTypeResolver::articles( $subject_key ) ) as $post_type ) {
			$result[ $post_type ] = array_map(
				function ( $post ) use ( $tax_slugs ) {
					$term_map = array();
					foreach ( $tax_slugs as $tax_slug ) {
						$slugs = $this->terms->getPostSlugs( $post->ID, $tax_slug );
						if ( ! empty( $slugs ) ) {
							$term_map[ $tax_slug ] = $slugs;
						}
					}
					return array(
						'post_title'   => $post->post_title,
						'post_content' => $post->post_content,
						'post_excerpt' => $post->post_excerpt,
						'post_status'  => $post->post_status,
						'post_date'    => $post->post_date,
						'menu_order'   => (int) $post->menu_order,
						'meta'         => $this->posts->getAllMeta( $post->ID ),
						'terms'        => $term_map,
					);
				},
				$this->posts->getAll( $post_type )
			);
		}

		return $result;
	}
}
