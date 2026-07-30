<?php

declare( strict_types=1 );

namespace Inc\Services\Subject\Bundle;

use Inc\Enums\Wp\PostMetaName;
use Inc\Managers\Wp\PostManager;
use Inc\Managers\Wp\TermManager;

/**
 * Class PostCollector
 *
 * Снимает запись в переносимое представление: поля поста + мета + термины.
 *
 * @package Inc\Services\Subject\Bundle
 *
 * ### Зачем выделено (Этап 1)
 *
 * Сбор задания, статьи, работы, контрольной, урока и курса — это буквально одна
 * и та же операция: разница только в CPT и наборе таксономий. До выделения она
 * жила приватным методом `SubjectExportService::collectPosts()` и умела ровно
 * два типа; с расширением до семи копипаста стала бы неизбежной.
 *
 * ### Что НЕ собирается
 *
 * - **Групповые форки уроков** (`fs_lms_forked_for_group`) — они принадлежат
 *   конкретной группе, а прогресс обучения по решению не переносится.
 * - **Служебная мета форка** (`fs_lms_forked_from`) — на целевом сайте исходный
 *   ID бессмыслен.
 * - **Записи в корзине и автосохранения** — мусор, а не контент.
 */
class PostCollector {

	/**
	 * Статусы, которые имеет смысл переносить.
	 *
	 * `trash`/`auto-draft` отсекаются намеренно: это не контент предмета.
	 */
	private const array TRANSFERABLE_STATUSES = array( 'publish', 'draft', 'pending', 'private', 'future', 'fs_archived' );

	/**
	 * Мета, не имеющая смысла на целевом сайте.
	 */
	private const array SKIPPED_META = array(
		'fs_lms_forked_from',
		'fs_lms_forked_for_group',
		'_edit_lock',
		'_edit_last',
	);

	/**
	 * Конструктор.
	 *
	 * @param PostManager $posts Менеджер записей
	 * @param TermManager $terms Менеджер терминов
	 */
	public function __construct(
		private readonly PostManager $posts,
		private readonly TermManager $terms,
	) {}

	/**
	 * Собирает все переносимые записи типа.
	 *
	 * @param string   $postType  CPT
	 * @param string[] $taxonomies Слаги таксономий, привязки к которым нужно снять
	 *
	 * @return array<int, array<string, mixed>> Список представлений записей
	 */
	public function collect( string $postType, array $taxonomies = array() ): array {
		$collected = array();

		foreach ( $this->posts->getAll( $postType ) as $post ) {
			if ( ! $this->isTransferable( $post ) ) {
				continue;
			}
			$collected[] = $this->snapshot( $post, $taxonomies );
		}

		return $collected;
	}

	/**
	 * Собирает конкретные записи по ID (для подтягивания глобальных задач).
	 *
	 * @param int[]    $postIds    ID записей
	 * @param string[] $taxonomies Слаги таксономий
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function collectByIds( array $postIds, array $taxonomies = array() ): array {
		$collected = array();

		foreach ( array_unique( array_map( 'intval', $postIds ) ) as $postId ) {
			$post = $this->posts->get( $postId );
			if ( null === $post || ! $this->isTransferable( $post ) ) {
				continue;
			}
			$collected[] = $this->snapshot( $post, $taxonomies );
		}

		return $collected;
	}

	/**
	 * Представление одной записи.
	 *
	 * @param \WP_Post $post       Запись
	 * @param string[] $taxonomies Слаги таксономий
	 *
	 * @return array<string, mixed>
	 */
	private function snapshot( \WP_Post $post, array $taxonomies ): array {
		$termMap = array();
		foreach ( $taxonomies as $taxonomy ) {
			$slugs = $this->terms->getPostSlugs( $post->ID, (string) $taxonomy );
			if ( ! empty( $slugs ) ) {
				$termMap[ (string) $taxonomy ] = $slugs;
			}
		}

		return array(
			'source_id'    => (int) $post->ID,
			'post_title'   => $post->post_title,
			'post_name'    => $post->post_name,
			'post_content' => $post->post_content,
			'post_excerpt' => $post->post_excerpt,
			'post_status'  => $post->post_status,
			'post_date'    => $post->post_date,
			'menu_order'   => (int) $post->menu_order,
			'meta'         => $this->collectMeta( (int) $post->ID ),
			'terms'        => $termMap,
		);
	}

	/**
	 * Мета записи без служебных ключей.
	 *
	 * @param int $postId ID записи
	 *
	 * @return array<string, mixed>
	 */
	private function collectMeta( int $postId ): array {
		$meta = $this->posts->getAllMeta( $postId );

		foreach ( self::SKIPPED_META as $key ) {
			unset( $meta[ $key ] );
		}

		return $meta;
	}

	/**
	 * Запись подлежит переносу.
	 *
	 * @param \WP_Post $post Запись
	 *
	 * @return bool
	 */
	private function isTransferable( \WP_Post $post ): bool {
		if ( ! in_array( $post->post_status, self::TRANSFERABLE_STATUSES, true ) ) {
			return false;
		}

		// Групповой форк урока — собственность группы, а не библиотеки предмета.
		return 0 === (int) $this->posts->getMeta( (int) $post->ID, PostMetaName::ForkedForGroup->value );
	}
}
