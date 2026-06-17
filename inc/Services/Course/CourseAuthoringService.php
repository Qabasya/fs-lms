<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\Managers\PostManager;
use Inc\Services\PostTypeResolver;

/**
 * Class CourseAuthoringService
 *
 * Бизнес-логика авторинга курса: кандидаты-уроки для селектора, валидация.
 * Доступ к данным — через PostManager.
 *
 * @package Inc\Services\Course
 */
class CourseAuthoringService {

	public function __construct(
		private readonly PostManager $posts,
	) {}

	/**
	 * Кандидаты-уроки для селектора курса (только текущий предмет).
	 *
	 * @param string $subjectKey
	 * @param string $scope  'mine' | 'subject'
	 * @param string $search
	 * @return array<int, array{id: int, title: string, author: int}>
	 */
	public function getLessonCandidates( string $subjectKey, string $scope = 'mine', string $search = '' ): array {
		$posts = $this->posts->search( PostTypeResolver::lessons( $subjectKey ), array(
			'limit'  => 50,
			'author' => 'mine' === $scope ? get_current_user_id() : 0,
			'search' => $search,
		) );

		return array_map( static fn( \WP_Post $post ): array => array(
			'id'     => $post->ID,
			'title'  => $post->post_title,
			'author' => (int) $post->post_author,
		), $posts );
	}

	/**
	 * Оставляет только уроки нужного предмета.
	 *
	 * @param string $subjectKey
	 * @param int[]  $lessonIds
	 * @return int[]
	 */
	public function validateLessonIds( string $subjectKey, array $lessonIds ): array {
		$post_type = PostTypeResolver::lessons( $subjectKey );

		return array_values( array_filter( $lessonIds, function ( int $id ) use ( $post_type ): bool {
			$post = $this->posts->get( $id );
			return $post instanceof \WP_Post && $post->post_type === $post_type;
		} ) );
	}
}
