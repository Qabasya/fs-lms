<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\Managers\Wp\PostManager;
use Inc\Managers\Wp\TermManager;
use Inc\Services\Subject\PostTypeResolver;
use Inc\Services\Task\TaskBundleService;

/**
 * Class WorkAuthoringService
 *
 * Бизнес-логика авторинга работы: кандидаты-элементы для селектора, коллекции.
 *
 * @package Inc\Services\Course
 */
class WorkAuthoringService {

	public function __construct(
		private readonly PostManager      $posts,
		private readonly TermManager      $terms,
		private readonly TaskBundleService $taskBundles,
	) {}

	/**
	 * Кандидаты-задания для селектора работы (только {key}_tasks текущего предмета).
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

		return array_map( fn( \WP_Post $post ): array => $this->withBundleChildren( array(
			'id'     => $post->ID,
			'title'  => $post->post_title,
			'author' => (int) $post->post_author,
			'type'   => 'task',
		), $post->ID ), $posts );
	}

	/**
	 * Если пост — parent связки (19/20/21), добавляет `bundle_children` (id+title
	 * трёх children): builder разворачивает такой пик в 3 слота вместо одного
	 * (см. .docs/Tasks.md, §3.3).
	 *
	 * @param array<string, mixed> $item
	 *
	 * @return array<string, mixed>
	 */
	private function withBundleChildren( array $item, int $postId ): array {
		$children = $this->taskBundles->childrenSummary( $postId );
		if ( ! empty( $children ) ) {
			$item['bundle_children'] = $children;
		}

		return $item;
	}

	/**
	 * Кандидаты-элементы: {key}_tasks + fs_lms_problems (unified).
	 *
	 * @param string $subjectKey
	 * @param int    $collectionTermId 0 = все коллекции (только для task)
	 * @param string $scope            'mine' | 'subject'
	 * @param string $search
	 * @return array<int, array{id: int, title: string, author: int, type: string}>
	 */
	public function getItemCandidates(
		string $subjectKey,
		int    $collectionTermId = 0,
		string $scope            = 'mine',
		string $search           = ''
	): array {
		$tasks    = $this->getTaskCandidates( $subjectKey, 0, $collectionTermId, $scope, $search );
		$problems = $this->getProblemCandidates( $search );

		return array_merge( $tasks, $problems );
	}

	/**
	 * Кандидаты из банка задач (fs_lms_problems, глобальный).
	 *
	 * @param string $search
	 * @return array<int, array{id: int, title: string, author: int, type: string}>
	 */
	public function getProblemCandidates( string $search = '' ): array {
		$posts = $this->posts->search( PostTypeResolver::problems(), array(
			'limit'  => 50,
			'search' => $search,
		) );

		return array_map( fn( \WP_Post $post ): array => $this->withBundleChildren( array(
			'id'     => $post->ID,
			'title'  => $post->post_title,
			'author' => (int) $post->post_author,
			'type'   => 'problem',
		), $post->ID ), $posts );
	}

	/**
	 * Создаёт черновик задачи (fs_lms_problems).
	 *
	 * @param string $title
	 * @return int  ID новой задачи (>0) или 0 при ошибке.
	 */
	public function createProblemDraft( string $title ): int {
		return $this->posts->insert( array(
			'post_title'  => $title,
			'post_type'   => PostTypeResolver::problems(),
			'post_status' => 'draft',
			'post_author' => get_current_user_id(),
		) );
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
	 * Валидирует item_ids: оставляет только {key}_tasks предмета и fs_lms_problems.
	 *
	 * @param string $subjectKey
	 * @param int[]  $itemIds
	 * @return int[]
	 */
	public function validateItemIds( string $subjectKey, array $itemIds ): array {
		$task_cpt = PostTypeResolver::tasks( $subjectKey );

		return array_values( array_filter( $itemIds, function ( int $id ) use ( $task_cpt ): bool {
			$post = $this->posts->get( $id );
			if ( ! $post instanceof \WP_Post ) {
				return false;
			}
			return $post->post_type === $task_cpt
				|| PostTypeResolver::isProblemPostType( $post->post_type );
		} ) );
	}
}
