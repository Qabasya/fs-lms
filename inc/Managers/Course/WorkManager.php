<?php

declare( strict_types=1 );

namespace Inc\Managers\Course;

use Inc\Managers\Wp\PostManager;

use Inc\DTO\Course\WorkDTO;
use Inc\Enums\Wp\PostMetaName;
use Inc\Services\Subject\PostTypeResolver;

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

	/**
	 * Частичное обновление списка элементов работы (степ-лист «только задачи»).
	 * `work_type` и прочая мета сохраняются (merge).
	 *
	 * @param int   $workId
	 * @param int[] $itemIds Упорядоченные ID заданий/задач.
	 */
	public function setItemIds( int $workId, array $itemIds ): bool {
		$post = $this->posts->get( $workId );
		if ( null === $post || ! PostTypeResolver::isWorkPostType( $post->post_type ) ) {
			return false;
		}

		$meta = $this->posts->getMeta( $workId, PostMetaName::Meta->value );
		$meta = is_array( $meta ) ? $meta : array();
		$meta['item_ids'] = array_values( array_filter( array_map( 'intval', $itemIds ) ) );

		$this->posts->updateMeta( $workId, PostMetaName::Meta->value, $meta );
		return true;
	}

	private function saveMeta( int $workId, WorkDTO $dto ): void {
		// instructions схлопнут в post_content (см. create/update) — в мете не дублируем.
		$this->posts->updateMeta( $workId, PostMetaName::Meta->value, array(
			'work_type' => $dto->workType->value,
			'item_ids'  => $dto->itemIds,
		) );
	}
}
