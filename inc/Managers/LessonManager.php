<?php

declare( strict_types=1 );

namespace Inc\Managers;

use Inc\DTO\Course\LessonDTO;
use Inc\DTO\Course\StepDTO;
use Inc\Enums\PostMetaName;
use Inc\Services\PostTypeResolver;

/**
 * Class LessonManager
 *
 * CRUD уроков: пост + meta через PostManager.
 *
 * @package Inc\Managers
 */
class LessonManager {

	public function __construct(
		private readonly PostManager $posts,
	) {}

	public function create( string $subjectKey, LessonDTO $dto ): int {
		$id = $this->posts->insert( array(
			'post_title'   => $dto->topic,
			'post_content' => '',
			'post_type'    => PostTypeResolver::lessons( $subjectKey ),
			'post_status'  => 'draft',
			'post_author'  => $dto->authorId ?: get_current_user_id(),
		) );

		if ( $id > 0 ) {
			$this->saveMeta( $id, $dto );
		}

		return $id;
	}

	public function update( int $lessonId, LessonDTO $dto ): bool {
		$updated = $this->posts->update( $lessonId, array(
			'post_title' => $dto->topic,
		) );

		if ( ! $updated ) {
			return false;
		}

		$this->saveMeta( $lessonId, $dto );
		return true;
	}

	public function get( int $lessonId ): ?LessonDTO {
		$post = $this->posts->get( $lessonId );
		if ( null === $post || ! PostTypeResolver::isLessonPostType( $post->post_type ) ) {
			return null;
		}

		$meta = $this->posts->getMeta( $post->ID, PostMetaName::Meta->value );
		return LessonDTO::fromPost( $post, is_array( $meta ) ? $meta : array() );
	}

	/**
	 * Уроки банка предмета (опубликованные + черновики).
	 *
	 * @param string $subjectKey
	 * @param array  $args  Опции выборки (PostManager::search): status, author, search, orderby, order, limit.
	 * @return LessonDTO[]
	 */
	public function getBankBySubject( string $subjectKey, array $args = array() ): array {
		// Exclude group forks — they belong to a specific group, not the shared library.
		$args['meta_query'] = array_merge(
			$args['meta_query'] ?? array(),
			array(
				array(
					'key'     => PostMetaName::ForkedForGroup->value,
					'compare' => 'NOT EXISTS',
				),
			)
		);

		$posts = $this->posts->search( PostTypeResolver::lessons( $subjectKey ), $args );

		return array_map( function ( \WP_Post $post ): LessonDTO {
			$meta = $this->posts->getMeta( $post->ID, PostMetaName::Meta->value );
			return LessonDTO::fromPost( $post, is_array( $meta ) ? $meta : array() );
		}, $posts );
	}

	public function delete( int $lessonId ): bool {
		$this->posts->delete( $lessonId );
		return true;
	}

	private function saveMeta( int $lessonId, LessonDTO $dto ): void {
		$this->posts->updateMeta( $lessonId, PostMetaName::Meta->value, array(
			'steps' => StepDTO::toList( $dto->steps ),
		) );
	}
}
