<?php

declare( strict_types=1 );

namespace Inc\Services;

use Inc\DTO\SubjectDTO;
use Inc\DTO\TaskTemplateAssignmentDTO;
use Inc\DTO\TaskTypeBoilerplateDTO;
use Inc\DTO\TaxonomyDataDTO;
use Inc\Managers\PostManager;
use Inc\Managers\TermManager;
use Inc\Repositories\BoilerplateRepository;
use Inc\Repositories\MetaBoxRepository;
use Inc\Repositories\SubjectRepository;
use Inc\Repositories\TaxonomyRepository;

class SubjectImportService {

	public function __construct(
		private readonly SubjectRepository    $subjects,
		private readonly TaxonomyRepository   $taxonomies,
		private readonly MetaBoxRepository    $metaboxes,
		private readonly BoilerplateRepository $boilerplates,
		private readonly TermManager          $terms,
		private readonly PostManager          $posts,
	) {}

	/**
	 * Imports a subject from a decoded JSON payload.
	 *
	 * @param array $data Decoded JSON array.
	 *
	 * @return string Imported subject name.
	 *
	 * @throws \InvalidArgumentException On malformed or duplicate input.
	 * @throws \RuntimeException         On storage failure.
	 */
	public function import( array $data ): string {
		if ( ! isset( $data['subject']['key'], $data['subject']['name'] ) ) {
			throw new \InvalidArgumentException( 'Неверный формат файла импорта' );
		}

		$key  = sanitize_title( (string) $data['subject']['key'] );
		$name = sanitize_text_field( (string) $data['subject']['name'] );

		if ( empty( $key ) || empty( $name ) ) {
			throw new \InvalidArgumentException( 'Ключ или название предмета пусты в файле импорта' );
		}

		if ( $this->subjects->getByKey( $key ) ) {
			throw new \InvalidArgumentException( "Предмет с ключом «{$key}» уже существует. Импорт невозможен." );
		}

		if ( ! $this->subjects->save( new SubjectDTO( $key, $name ) ) ) {
			throw new \RuntimeException( 'Критическая ошибка при создании записи предмета в БД' );
		}

		$this->importTaxonomies( $key, $data['taxonomies'] ?? array() );
		$this->importMetaboxes( $key, $data['metaboxes'] ?? array() );
		$this->importBoilerplates( $key, $data['boilerplates'] ?? array() );
		$this->importTerms( $data['terms'] ?? array() );
		$this->importPosts( $data['posts'] ?? array() );

		return $name;
	}

	private function importTaxonomies( string $key, array $taxonomies ): void {
		foreach ( $taxonomies as $tax_slug => $tax_data ) {
			$this->taxonomies->save( new TaxonomyDataDTO(
				slug:         sanitize_title( (string) $tax_slug ),
				name:         sanitize_text_field( $tax_data['name'] ?? '' ),
				subject_key:  $key,
				display_type: sanitize_text_field( $tax_data['display_type'] ?? 'select' ),
				is_required:  (bool) ( $tax_data['is_required'] ?? false ),
			) );
		}
	}

	private function importMetaboxes( string $key, array $metaboxes ): void {
		foreach ( $metaboxes as $task_number => $template_id ) {
			$this->metaboxes->save( new TaskTemplateAssignmentDTO(
				$key,
				sanitize_text_field( (string) $task_number ),
				sanitize_text_field( (string) $template_id ),
			) );
		}
	}

	private function importBoilerplates( string $key, array $boilerplates ): void {
		foreach ( $boilerplates as $term_slug => $bp_list ) {
			foreach ( (array) $bp_list as $bp ) {
				$this->boilerplates->save( new TaskTypeBoilerplateDTO(
					uid:         sanitize_text_field( $bp['uid'] ?? uniqid( 'bp_', true ) ),
					subject_key: $key,
					term_slug:   sanitize_text_field( (string) $term_slug ),
					title:       sanitize_text_field( $bp['title'] ?? '' ),
					content:     wp_kses_post( $bp['content'] ?? '' ),
					is_default:  (bool) ( $bp['is_default'] ?? false ),
				) );
			}
		}
	}

	private function importTerms( array $terms_by_taxonomy ): void {
		foreach ( $terms_by_taxonomy as $tax_slug => $term_list ) {
			$taxonomy = sanitize_title( (string) $tax_slug );
			$this->terms->ensureTaxonomy( $taxonomy );

			foreach ( (array) $term_list as $term_data ) {
				$name = sanitize_text_field( $term_data['name'] ?? '' );
				if ( empty( $name ) ) {
					continue;
				}
				$this->terms->insert(
					$name,
					$taxonomy,
					array(
						'slug'        => sanitize_title( $term_data['slug'] ?? $name ),
						'description' => sanitize_text_field( $term_data['description'] ?? '' ),
					)
				);
			}
		}
	}

	private function importPosts( array $posts_data ): void {
		foreach ( $posts_data as $post_type => $post_list ) {
			foreach ( (array) $post_list as $post_data ) {
				$post_id = $this->posts->insert( array(
					'post_type'    => sanitize_key( (string) $post_type ),
					'post_title'   => sanitize_text_field( $post_data['post_title'] ?? '' ),
					'post_content' => wp_kses_post( $post_data['post_content'] ?? '' ),
					'post_excerpt' => sanitize_text_field( $post_data['post_excerpt'] ?? '' ),
					'post_status'  => sanitize_text_field( $post_data['post_status'] ?? 'publish' ),
					'post_date'    => sanitize_text_field( $post_data['post_date'] ?? '' ),
					'menu_order'   => absint( $post_data['menu_order'] ?? 0 ),
				) );

				if ( ! $post_id ) {
					continue;
				}

				foreach ( $post_data['meta'] ?? array() as $meta_key => $meta_value ) {
					$this->posts->updateMeta( $post_id, sanitize_key( (string) $meta_key ), $meta_value );
				}

				foreach ( $post_data['terms'] ?? array() as $tax_slug => $term_slugs ) {
					$this->terms->setPostTerms( $post_id, (array) $term_slugs, sanitize_title( (string) $tax_slug ) );
				}
			}
		}
	}
}
