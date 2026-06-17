<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\Enums\PostMetaName;
use Inc\Enums\WorkType;
use Inc\Managers\PostManager;
use Inc\Services\PostTypeResolver;

/**
 * Class LessonAuthoringService
 *
 * Бизнес-логика авторинга урока: кандидаты-работы для селектора, статьи, валидация.
 * Урок ссылается на работы, не на задачи. Доступ к данным — через PostManager.
 *
 * @package Inc\Services\Course
 */
class LessonAuthoringService {

	public function __construct(
		private readonly PostManager $posts,
	) {}

	/**
	 * Кандидаты-работы для селектора урока (только текущий предмет).
	 *
	 * @param string $subjectKey
	 * @param string $workType  '' = все типы
	 * @param string $scope     'mine' | 'subject'
	 * @param string $search
	 * @return array<int, array{id: int, title: string, work_type: string, author: int}>
	 */
	public function getWorkCandidates(
		string $subjectKey,
		string $workType = '',
		string $scope    = 'mine',
		string $search   = ''
	): array {
		$posts = $this->posts->search( PostTypeResolver::works( $subjectKey ), array(
			'limit'  => 50,
			'author' => 'mine' === $scope ? get_current_user_id() : 0,
			'search' => $search,
		) );

		// Фильтр по типу работы — на стороне PHP (тип лежит в сериализованной мете).
		$filter_type = WorkType::tryFrom( $workType );

		$result = array();
		foreach ( $posts as $post ) {
			$meta      = $this->posts->getMeta( $post->ID, PostMetaName::Meta->value );
			$post_type = is_array( $meta ) ? (string) ( $meta['work_type'] ?? '' ) : '';

			if ( null !== $filter_type && $post_type !== $filter_type->value ) {
				continue;
			}

			$result[] = array(
				'id'        => $post->ID,
				'title'     => $post->post_title,
				'work_type' => $post_type,
				'author'    => (int) $post->post_author,
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
		$result = array();
		foreach ( $this->posts->getAll( PostTypeResolver::articles( $subjectKey ) ) as $post ) {
			$result[ $post->ID ] = $post->post_title;
		}

		return $result;
	}

	/**
	 * Оставляет только работы нужного предмета.
	 *
	 * @param string $subjectKey
	 * @param int[]  $workIds
	 * @return int[]
	 */
	public function validateWorkIds( string $subjectKey, array $workIds ): array {
		$post_type = PostTypeResolver::works( $subjectKey );

		return array_values( array_filter( $workIds, function ( int $id ) use ( $post_type ): bool {
			$post = $this->posts->get( $id );
			return $post instanceof \WP_Post && $post->post_type === $post_type;
		} ) );
	}
}
