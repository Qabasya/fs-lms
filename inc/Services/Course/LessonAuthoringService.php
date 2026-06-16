<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\Managers\PostManager;
use Inc\Managers\TermManager;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Services\PostTypeResolver;

/**
 * Class LessonAuthoringService
 *
 * Бизнес-логика авторинга уроков: кандидаты для бакетов, статьи, типы заданий.
 *
 * @package Inc\Services\Course
 */
class LessonAuthoringService {

	public function __construct(
		private readonly PostManager       $posts,
		private readonly TermManager       $terms,
		private readonly SubjectRepository $subjects,
	) {}

	/**
	 * Кандидаты для бакета урока.
	 *
	 * @param string $subjectKey
	 * @param int    $taskTypeTermId  0 = все типы
	 * @param int    $collectionTermId 0 = все коллекции
	 * @param string $scope           'mine' | 'subject'
	 * @param string $search
	 * @return array<int, array{id: int, title: string, author: int}>
	 */
	public function getTaskCandidates(
		string $subjectKey,
		int    $taskTypeTermId   = 0,
		int    $collectionTermId = 0,
		string $scope            = 'mine',
		string $search           = ''
	): array {
		$post_type = PostTypeResolver::tasks( $subjectKey );

		$args = array(
			'post_type'      => $post_type,
			'post_status'    => array( 'publish', 'draft' ),
			'numberposts'    => 50,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'suppress_filters' => false,
		);

		if ( 'mine' === $scope ) {
			$args['author'] = get_current_user_id();
		}

		if ( $search !== '' ) {
			$args['s'] = $search;
		}

		if ( $taskTypeTermId > 0 ) {
			$args['tax_query'] = array(
				array(
					'taxonomy' => PostTypeResolver::getTaskTaxonomy( $subjectKey ),
					'field'    => 'term_id',
					'terms'    => $taskTypeTermId,
				),
			);
		}

		if ( $collectionTermId > 0 ) {
			$collection_query = array(
				'field'    => 'term_id',
				'terms'    => $collectionTermId,
			);

			// Добавляем запрос по коллекции в tax_query
			$existing = $args['tax_query'] ?? array();
			if ( ! empty( $existing ) ) {
				$existing['relation'] = 'AND';
				$existing[]           = $collection_query;
				$args['tax_query']    = $existing;
			} else {
				$args['tax_query'] = array( $collection_query );
			}
		}

		$posts  = get_posts( $args );
		$result = array();

		foreach ( $posts as $post ) {
			$result[] = array(
				'id'     => $post->ID,
				'title'  => $post->post_title,
				'author' => (int) $post->post_author,
			);
		}

		return $result;
	}

	/**
	 * Статьи предмета для ArticleRefField.
	 *
	 * @param string $subjectKey
	 * @return array<int, string> post_id => title
	 */
	public function getArticles( string $subjectKey ): array {
		$posts = $this->posts->getAll( PostTypeResolver::articles( $subjectKey ) );

		$result = array();
		foreach ( $posts as $post ) {
			$result[ $post->ID ] = $post->post_title;
		}

		return $result;
	}

	/**
	 * Типы заданий предмета для TaskTypeField.
	 *
	 * @param string $subjectKey
	 * @return array<int, string> term_id => name
	 */
	public function getTaskTypes( string $subjectKey ): array {
		$taxonomy = PostTypeResolver::getTaskTaxonomy( $subjectKey );
		$terms    = get_terms( array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
		) );

		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return array();
		}

		$result = array();
		foreach ( $terms as $term ) {
			$result[ $term->term_id ] = $term->name;
		}

		return $result;
	}

	/**
	 * Термины коллекций (пользовательские таксономии на заданиях) для фильтра бакета.
	 *
	 * @param string $subjectKey
	 * @return array<int, string> term_id => name
	 */
	public function getCollections( string $subjectKey ): array {
		$post_type   = PostTypeResolver::tasks( $subjectKey );
		$taxonomies  = get_object_taxonomies( $post_type );
		$task_number = PostTypeResolver::getTaskTaxonomy( $subjectKey );

		$result = array();
		foreach ( $taxonomies as $tax_slug ) {
			if ( $tax_slug === $task_number ) {
				continue;
			}
			$terms = get_terms( array(
				'taxonomy'   => $tax_slug,
				'hide_empty' => false,
			) );
			if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
				continue;
			}
			foreach ( $terms as $term ) {
				$result[ $term->term_id ] = $term->name;
			}
		}

		return $result;
	}

	/**
	 * Валидирует task_ids — оставляет только существующие посты нужного предмета.
	 *
	 * @param string $subjectKey
	 * @param int[]  $taskIds
	 * @return int[]
	 */
	public function validateTaskIds( string $subjectKey, array $taskIds ): array {
		$post_type = PostTypeResolver::tasks( $subjectKey );

		return array_values( array_filter( $taskIds, static function ( int $id ) use ( $post_type ): bool {
			$post = get_post( $id );
			return $post instanceof \WP_Post && $post->post_type === $post_type;
		} ) );
	}
}
