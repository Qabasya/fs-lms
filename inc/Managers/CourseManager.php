<?php

declare( strict_types=1 );

namespace Inc\Managers;

use Inc\DTO\Course\CourseDTO;
use Inc\Enums\PostMetaName;
use Inc\Services\PostTypeResolver;

/**
 * Class CourseManager
 *
 * CRUD курсов: пост (PostManager) + meta (MetaBoxManager).
 *
 * @package Inc\Managers
 */
class CourseManager {

	public function __construct(
		private readonly PostManager    $posts,
		private readonly MetaBoxManager $metaBoxManager,
	) {}

	public function create( string $subjectKey, CourseDTO $dto ): int {
		$id = $this->posts->insert( array(
			'post_title'   => $dto->title,
			'post_content' => $dto->descriptionHtml,
			'post_type'    => PostTypeResolver::courses( $subjectKey ),
			'post_status'  => 'draft',
			'post_author'  => $dto->authorId ?: get_current_user_id(),
		) );

		if ( $id > 0 ) {
			$this->saveMeta( $id, $dto );
		}

		return $id;
	}

	public function update( int $courseId, CourseDTO $dto ): bool {
		$result = wp_update_post( array(
			'ID'           => $courseId,
			'post_title'   => $dto->title,
			'post_content' => $dto->descriptionHtml,
		) );

		if ( is_wp_error( $result ) || 0 === $result ) {
			return false;
		}

		$this->saveMeta( $courseId, $dto );
		return true;
	}

	public function get( int $courseId ): ?CourseDTO {
		$post = get_post( $courseId );
		if ( ! $post instanceof \WP_Post || ! PostTypeResolver::isCoursePostType( $post->post_type ) ) {
			return null;
		}

		$meta = get_post_meta( $post->ID, PostMetaName::Meta->value, true );
		return CourseDTO::fromPost( $post, is_array( $meta ) ? $meta : array() );
	}

	/**
	 * Курсы банка предмета.
	 *
	 * @param string $subjectKey
	 * @param array  $args Дополнительные аргументы get_posts().
	 * @return CourseDTO[]
	 */
	public function getBankBySubject( string $subjectKey, array $args = array() ): array {
		$posts = get_posts( array_merge( array(
			'post_type'   => PostTypeResolver::courses( $subjectKey ),
			'post_status' => array( 'publish', 'draft' ),
			'numberposts' => -1,
			'orderby'     => 'title',
			'order'       => 'ASC',
		), $args ) );

		return array_map( static function ( \WP_Post $post ): CourseDTO {
			$meta = get_post_meta( $post->ID, PostMetaName::Meta->value, true );
			return CourseDTO::fromPost( $post, is_array( $meta ) ? $meta : array() );
		}, $posts );
	}

	public function delete( int $courseId ): bool {
		return (bool) wp_delete_post( $courseId, true );
	}

	private function saveMeta( int $courseId, CourseDTO $dto ): void {
		$this->posts->updateMeta( $courseId, PostMetaName::Meta->value, array(
			'lesson_ids' => $dto->lessonIds,
		) );
	}
}
