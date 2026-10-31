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
		$this->assertTotalPoints( $dto->toArray()['task_points'] );
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
		$this->assertTotalPoints( $dto->toArray()['task_points'] );
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
	 * @param array<int, int>|null $taskPoints Карта баллов; null сохраняет прежние веса.
	 *
	 * @throws \InvalidArgumentException Некорректные баллы либо превышение суммы 9999.
	 */
	public function setItemIds( int $workId, array $itemIds, ?array $taskPoints = null ): bool {
		$post = $this->posts->get( $workId );
		if ( null === $post || ! PostTypeResolver::isWorkPostType( $post->post_type ) ) {
			return false;
		}

		// Гард дублей: одна задача не может стоять в двух слотах (задача 6).
		$ids = array_values( array_filter( array_map( 'intval', $itemIds ) ) );
		if ( count( $ids ) !== count( array_unique( $ids ) ) ) {
			return false;
		}

		$meta       = $this->posts->getMeta( $workId, PostMetaName::Meta->value );
		$meta       = is_array( $meta ) ? $meta : array();
		$points     = $taskPoints ?? ( is_array( $meta['task_points'] ?? null ) ? $meta['task_points'] : array() );
		$normalized = array();
		foreach ( $ids as $id ) {
			$value = array_key_exists( $id, $points ) ? $points[ $id ] : 1;
			if ( ( ! is_int( $value ) && ! is_float( $value ) && ! is_string( $value ) ) || ! preg_match( '/^[0-9]+$/', (string) $value ) || (int) $value < 1 || (int) $value > 999 ) {
				throw new \InvalidArgumentException( 'Баллы за задачу должны быть целым числом от 1 до 999.' );
			}
			$normalized[ $id ] = (int) $value;
		}
		$this->assertTotalPoints( $normalized );
		$meta['item_ids']    = $ids;
		$meta['task_points'] = $normalized;

		$this->posts->updateMeta( $workId, PostMetaName::Meta->value, $meta );
		return true;
	}

	/**
	 * Проверяет вместимость суммы баллов в существующем хранилище результатов работы.
	 *
	 * @throws \InvalidArgumentException Сумма не помещается в поле результата работы.
	 */
	private function assertTotalPoints( array $points ): void {
		if ( array_sum( $points ) > 9999 ) {
			throw new \InvalidArgumentException( 'Сумма баллов за работу не должна превышать 9999.' );
		}
	}

	private function saveMeta( int $workId, WorkDTO $dto ): void {
		// instructions схлопнут в post_content (см. create/update) — в мете не дублируем.
		$existing = $this->posts->getMeta( $workId, PostMetaName::Meta->value );
		$meta     = array_merge(
			is_array( $existing ) ? $existing : array(),
			array(
				'work_type'    => $dto->workType->value,
				'item_ids'     => $dto->itemIds,
				'max_attempts' => $dto->maxAttempts,
				'task_points'  => $dto->toArray()['task_points'],
			)
		);
		$this->posts->updateMeta( $workId, PostMetaName::Meta->value, $meta );
		// Плоский ключ для фильтрации в list table (meta_query не умеет в сериализованные массивы).
		$this->posts->updateMeta( $workId, PostMetaName::WorkType->value, $dto->workType->value );
	}
}
