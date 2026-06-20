<?php

declare( strict_types=1 );

namespace Inc\Managers;

use Inc\DTO\Course\CourseDTO;
use Inc\DTO\Course\ModuleDTO;
use Inc\Enums\Wp\PostMetaName;
use Inc\Services\PostTypeResolver;

/**
 * Class CourseManager
 *
 * CRUD курсов: пост + meta через PostManager.
 *
 * @package Inc\Managers
 */
class CourseManager {

	public function __construct(
		private readonly PostManager $posts,
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
		$updated = $this->posts->update( $courseId, array(
			'post_title'   => $dto->title,
			'post_content' => $dto->descriptionHtml,
		) );

		if ( ! $updated ) {
			return false;
		}

		$this->saveMeta( $courseId, $dto );
		return true;
	}

	public function get( int $courseId ): ?CourseDTO {
		$post = $this->posts->get( $courseId );
		if ( null === $post || ! PostTypeResolver::isCoursePostType( $post->post_type ) ) {
			return null;
		}

		$meta = $this->posts->getMeta( $post->ID, PostMetaName::Meta->value );
		return CourseDTO::fromPost( $post, is_array( $meta ) ? $meta : array() );
	}

	/**
	 * Курсы банка предмета.
	 *
	 * @param string $subjectKey
	 * @param array  $args Опции выборки (PostManager::search): status, author, search, orderby, order, limit.
	 * @return CourseDTO[]
	 */
	public function getBankBySubject( string $subjectKey, array $args = array() ): array {
		$posts = $this->posts->search( PostTypeResolver::courses( $subjectKey ), $args );

		return array_map( function ( \WP_Post $post ): CourseDTO {
			$meta = $this->posts->getMeta( $post->ID, PostMetaName::Meta->value );
			return CourseDTO::fromPost( $post, is_array( $meta ) ? $meta : array() );
		}, $posts );
	}

	public function delete( int $courseId ): bool {
		$this->posts->delete( $courseId );
		return true;
	}

	private function saveMeta( int $courseId, CourseDTO $dto ): void {
		$this->posts->updateMeta( $courseId, PostMetaName::Meta->value, array(
			'modules' => ModuleDTO::toList( $dto->modules ),
		) );
	}
}
