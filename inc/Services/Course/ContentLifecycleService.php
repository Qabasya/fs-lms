<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\Managers\PostManager;
use Inc\Services\PostTypeResolver;

/**
 * Class ContentLifecycleService
 *
 * Жизненный цикл контента банков: draft → publish ⇄ fs_archived.
 *
 * Инвариант: ссылка резолвится, пока существует пост; статус влияет только на
 * видимость в селекторах, не рвёт ссылки. Архив — ретайр без удаления.
 *
 * @package Inc\Services\Course
 */
class ContentLifecycleService {

	public const STATUS_ARCHIVED = 'fs_archived';

	public function __construct(
		private readonly PostManager $posts,
	) {}

	/**
	 * Переводит элемент банка в архив.
	 *
	 * @param int $postId
	 * @return bool
	 */
	public function archive( int $postId ): bool {
		return $this->setStatus( $postId, self::STATUS_ARCHIVED );
	}

	/**
	 * Возвращает элемент из архива в publish.
	 *
	 * @param int $postId
	 * @return bool
	 */
	public function unarchive( int $postId ): bool {
		return $this->setStatus( $postId, 'publish' );
	}

	/**
	 * @param int    $postId
	 * @param string $status
	 * @return bool
	 */
	private function setStatus( int $postId, string $status ): bool {
		$post = $this->posts->get( $postId );
		if ( ! $post instanceof \WP_Post || ! PostTypeResolver::isBankPostType( $post->post_type ) ) {
			return false;
		}

		return $this->posts->updateStatus( $postId, $status );
	}
}
