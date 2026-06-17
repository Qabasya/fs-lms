<?php

declare( strict_types=1 );

namespace Inc\Managers;

use Inc\DTO\Course\WorkDTO;
use Inc\Enums\PostMetaName;
use Inc\Services\PostTypeResolver;

/**
 * Class WorkManager
 *
 * CRUD работ: пост (PostManager) + meta (MetaBoxManager).
 *
 * @package Inc\Managers
 */
class WorkManager {

	public function __construct(
		private readonly PostManager    $posts,
		private readonly MetaBoxManager $metaBoxManager,
	) {}

	public function create( string $subjectKey, WorkDTO $dto ): int {
		$id = $this->posts->insert( array(
			'post_title'   => $dto->title,
			'post_content' => $dto->instructions,
			'post_type'    => PostTypeResolver::works( $subjectKey ),
			'post_status'  => 'draft',
			'post_author'  => $dto->authorId ?: get_current_user_id(),
		) );

		if ( $id > 0 ) {
			$this->saveMeta( $id, $dto );
		}

		return $id;
	}

	public function update( int $workId, WorkDTO $dto ): bool {
		$result = wp_update_post( array(
			'ID'         => $workId,
			'post_title' => $dto->title,
		) );

		if ( is_wp_error( $result ) || 0 === $result ) {
			return false;
		}

		$this->saveMeta( $workId, $dto );
		return true;
	}

	public function get( int $workId ): ?WorkDTO {
		$post = get_post( $workId );
		if ( ! $post instanceof \WP_Post || ! PostTypeResolver::isWorkPostType( $post->post_type ) ) {
			return null;
		}

		$meta = get_post_meta( $post->ID, PostMetaName::Meta->value, true );
		return WorkDTO::fromPost( $post, is_array( $meta ) ? $meta : array() );
	}

	/**
	 * Работы банка предмета.
	 *
	 * @param string $subjectKey
	 * @param array  $args Дополнительные аргументы get_posts().
	 * @return WorkDTO[]
	 */
	public function getBankBySubject( string $subjectKey, array $args = array() ): array {
		$posts = get_posts( array_merge( array(
			'post_type'   => PostTypeResolver::works( $subjectKey ),
			'post_status' => array( 'publish', 'draft' ),
			'numberposts' => -1,
			'orderby'     => 'title',
			'order'       => 'ASC',
		), $args ) );

		return array_map( static function ( \WP_Post $post ): WorkDTO {
			$meta = get_post_meta( $post->ID, PostMetaName::Meta->value, true );
			return WorkDTO::fromPost( $post, is_array( $meta ) ? $meta : array() );
		}, $posts );
	}

	public function delete( int $workId ): bool {
		return (bool) wp_delete_post( $workId, true );
	}

	private function saveMeta( int $workId, WorkDTO $dto ): void {
		$this->posts->updateMeta( $workId, PostMetaName::Meta->value, array(
			'work_type'    => $dto->workType->value,
			'item_ids'     => $dto->itemIds,
			'instructions' => $dto->instructions,
		) );
	}
}
