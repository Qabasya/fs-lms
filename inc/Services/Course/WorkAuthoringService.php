<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\Managers\PostManager;
use Inc\Managers\TermManager;
use Inc\Services\PostTypeResolver;

/**
 * Class WorkAuthoringService
 *
 * Бизнес-логика авторинга работы: кандидаты-задания для селектора, коллекции, валидация.
 * Доступ к данным — через PostManager/TermManager (без прямых get_posts/get_post).
 *
 * @package Inc\Services\Course
 */
class WorkAuthoringService {

	public function __construct(
		private readonly PostManager $posts,
		private readonly TermManager $terms,
	) {}

	/**
	 * Кандидаты-задания для селектора работы (только текущий предмет).
	 *
	 * @param string $subjectKey
	 * @param int    $taskTypeTermId   0 = все типы
	 * @param int    $collectionTermId 0 = все коллекции
	 * @param string $scope            'mine' | 'subject'
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
		$tax_query = array();
		if ( $taskTypeTermId > 0 ) {
			$tax_query[] = array(
				'taxonomy' => PostTypeResolver::getTaskTaxonomy( $subjectKey ),
				'field'    => 'term_id',
				'terms'    => $taskTypeTermId,
			);
		}
		if ( $collectionTermId > 0 ) {
			$tax_query[] = array(
				'field' => 'term_id',
				'terms' => $collectionTermId,
			);
		}
		if ( count( $tax_query ) > 1 ) {
			$tax_query['relation'] = 'AND';
		}

		$posts = $this->posts->search( PostTypeResolver::tasks( $subjectKey ), array(
			'limit'     => 50,
			'author'    => 'mine' === $scope ? get_current_user_id() : 0,
			'search'    => $search,
			'tax_query' => $tax_query,
		) );

		return array_map( static fn( \WP_Post $post ): array => array(
			'id'     => $post->ID,
			'title'  => $post->post_title,
			'author' => (int) $post->post_author,
		), $posts );
	}

	/**
	 * Термины коллекций (пользовательские таксономии на заданиях).
	 *
	 * @param string $subjectKey
	 * @return array<int, string> term_id => name
	 */
	public function getCollections( string $subjectKey ): array {
		return $this->terms->listCollections(
			PostTypeResolver::tasks( $subjectKey ),
			PostTypeResolver::getTaskTaxonomy( $subjectKey )
		);
	}

	/**
	 * Оставляет только задания нужного предмета.
	 *
	 * @param string $subjectKey
	 * @param int[]  $taskIds
	 * @return int[]
	 */
	public function validateTaskIds( string $subjectKey, array $taskIds ): array {
		$post_type = PostTypeResolver::tasks( $subjectKey );

		return array_values( array_filter( $taskIds, function ( int $id ) use ( $post_type ): bool {
			$post = $this->posts->get( $id );
			return $post instanceof \WP_Post && $post->post_type === $post_type;
		} ) );
	}
}
