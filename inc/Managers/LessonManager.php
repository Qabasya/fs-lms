<?php

declare( strict_types=1 );

namespace Inc\Managers;

use Inc\DTO\Course\LessonDTO;
use Inc\Enums\PostMetaName;
use Inc\Services\PostTypeResolver;

/**
 * Class LessonManager
 *
 * CRUD уроков: пост (PostManager) + meta (MetaBoxManager).
 *
 * @package Inc\Managers
 */
class LessonManager {

	public function __construct(
		private readonly PostManager    $posts,
		private readonly MetaBoxManager $metaBoxManager,
	) {}

	public function create( string $subjectKey, LessonDTO $dto ): int {
		$id = $this->posts->insert( array(
			'post_title'   => $dto->topic,
			'post_content' => $dto->theoryHtml,
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
		$result = wp_update_post( array(
			'ID'           => $lessonId,
			'post_title'   => $dto->topic,
			'post_content' => $dto->theoryHtml,
		) );

		if ( is_wp_error( $result ) || $result === 0 ) {
			return false;
		}

		$this->saveMeta( $lessonId, $dto );
		return true;
	}

	public function get( int $lessonId ): ?LessonDTO {
		$post = get_post( $lessonId );
		if ( ! $post instanceof \WP_Post || ! PostTypeResolver::isLessonPostType( $post->post_type ) ) {
			return null;
		}

		$meta = get_post_meta( $post->ID, PostMetaName::Meta->value, true );
		return LessonDTO::fromPost( $post, is_array( $meta ) ? $meta : array() );
	}

	/**
	 * Уроки банка предмета (опубликованные + черновики).
	 *
	 * @param string $subjectKey
	 * @param array  $args  Дополнительные аргументы get_posts()
	 * @return LessonDTO[]
	 */
	public function getBankBySubject( string $subjectKey, array $args = array() ): array {
		$posts = get_posts( array_merge( array(
			'post_type'      => PostTypeResolver::lessons( $subjectKey ),
			'post_status'    => array( 'publish', 'draft' ),
			'numberposts'    => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		), $args ) );

		return array_map( function ( \WP_Post $post ): LessonDTO {
			$meta = get_post_meta( $post->ID, PostMetaName::Meta->value, true );
			return LessonDTO::fromPost( $post, is_array( $meta ) ? $meta : array() );
		}, $posts );
	}

	public function delete( int $lessonId ): bool {
		return (bool) wp_delete_post( $lessonId, true );
	}

	private function saveMeta( int $lessonId, LessonDTO $dto ): void {
		$this->posts->updateMeta( $lessonId, PostMetaName::Meta->value, array(
			'theory_article_id' => $dto->theoryArticleId,
			'task_type'         => $dto->taskType,
			'practice'          => $dto->practice,
			'independent'       => $dto->independent,
			'homework'          => $dto->homework,
		) );
	}
}
