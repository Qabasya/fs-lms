<?php

declare( strict_types=1 );

namespace Inc\Managers;

use Inc\DTO\Course\WorkDTO;
use Inc\Enums\PostMetaName;
use Inc\Services\PostTypeResolver;

/**
 * Class WorkManager
 *
 * CRUD работ: пост + meta через PostManager.
 *
 * @package Inc\Managers
 */
class WorkManager {

	public function __construct(
		private readonly PostManager $posts,
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
		$updated = $this->posts->update( $workId, array(
			'post_title' => $dto->title,
		) );

		if ( ! $updated ) {
			return false;
		}

		$this->saveMeta( $workId, $dto );
		return true;
	}

	public function get( int $workId ): ?WorkDTO {
		$post = $this->posts->get( $workId );
		if ( null === $post || ! PostTypeResolver::isWorkPostType( $post->post_type ) ) {
			return null;
		}

		$meta = $this->posts->getMeta( $post->ID, PostMetaName::Meta->value );
		return WorkDTO::fromPost( $post, is_array( $meta ) ? $meta : array() );
	}

	/**
	 * Работы банка предмета.
	 *
	 * @param string $subjectKey
	 * @param array  $args Опции выборки (PostManager::search): status, author, search, orderby, order, limit.
	 * @return WorkDTO[]
	 */
	public function getBankBySubject( string $subjectKey, array $args = array() ): array {
		$posts = $this->posts->search( PostTypeResolver::works( $subjectKey ), $args );

		return array_map( function ( \WP_Post $post ): WorkDTO {
			$meta = $this->posts->getMeta( $post->ID, PostMetaName::Meta->value );
			return WorkDTO::fromPost( $post, is_array( $meta ) ? $meta : array() );
		}, $posts );
	}

	public function delete( int $workId ): bool {
		$this->posts->delete( $workId );
		return true;
	}

	private function saveMeta( int $workId, WorkDTO $dto ): void {
		$this->posts->updateMeta( $workId, PostMetaName::Meta->value, array(
			'work_type'    => $dto->workType->value,
			'item_ids'     => $dto->itemIds,
			'instructions' => $dto->instructions,
		) );
	}
}
