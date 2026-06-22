<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\Managers\Wp\PostManager;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Services\Subject\PostTypeResolver;

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
		private readonly PostManager       $posts,
		private readonly SubjectRepository $subjects,
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
	 * Кандидаты-уроки по всем предметам (доп. кросс-предметные занятия).
	 * Каждый кандидат несёт subject_key/subject_name для подписи в пикере.
	 *
	 * @param string $search
	 * @return array<int, array{id: int, title: string, author: int, subject_key: string, subject_name: string}>
	 */
	public function getLessonCandidatesAllSubjects( string $search = '' ): array {
		$result = array();
		foreach ( $this->subjects->readActive() as $subject ) {
			$posts = $this->posts->search( PostTypeResolver::lessons( $subject->key ), array(
				'limit'  => 25,
				'search' => $search,
			) );
			foreach ( $posts as $post ) {
				$result[] = array(
					'id'           => $post->ID,
					'title'        => $post->post_title,
					'author'       => (int) $post->post_author,
					'subject_key'  => $subject->key,
					'subject_name' => $subject->name,
				);
			}
		}

		return $result;
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
