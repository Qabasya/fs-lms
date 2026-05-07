<?php

declare(strict_types=1);

namespace Inc\Services;

use Inc\Repositories\ArticleRepository;

/**
 * Class ArticleService
 *
 * Сервис для получения статей предмета на frontend-странице задания.
 *
 * Инкапсулирует выборку связанных и случайных статей через ArticleRepository
 * и приводит записи к единому формату для шаблона.
 *
 * @package Inc\Services
 */
class ArticleService {
	public function __construct(
		private readonly ArticleRepository $article_repository,
	) {}

	/**
	 * Возвращает статьи текущего предмета, связанные с типом задания.
	 *
	 * @param string $subject_key
	 * @param \WP_Term|null $current_task_type
	 *
	 * @return array Список связанных статей.
	 */
	public function getRelatedArticles( string $subject_key, ?\WP_Term $current_task_type ): array {
		if ( $subject_key === '' || ! $current_task_type ) {
			return array();
		}

		$post_type = PostTypeResolver::articles( $subject_key );

		$posts = $this->article_repository->findRelated(
			$post_type,
			$current_task_type->term_id,
			$current_task_type->taxonomy
		);

		return $this->formatArticlePosts( $posts );
	}

	/**
	 * Возвращает рандомный список статей
	 *
	 * @param string $subject_key
	 *
	 * @return array Список рандомных статей
	 */
	public function getRandomArticles( string $subject_key ): array {
		if ( $subject_key === '' ) {
			return array();
		}

		$post_type = PostTypeResolver::articles( $subject_key );
		$posts     = $this->article_repository->findRandom( $post_type );

		return $this->formatArticlePosts( $posts );
	}

	/**
	 * Приводит записи статей к единому формату для шаблона.
	 *
	 * @param array $posts
	 *
	 * @return array Список статей.
	 */
	private function formatArticlePosts( array $posts ): array {
		$articles = array();

		foreach ( $posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}

			$articles[] = array(
				'id'        => $post->ID,
				'title'     => get_the_title( $post->ID ),
				'url'       => get_permalink( $post->ID ),
				'excerpt'   => $this->getArticleExcerpt( $post ),
			);
		}

		return $articles;
	}

	/**
	 * Возвращает короткий текст статьи для карточки на frontend.
	 *
	 * Использует ручной excerpt, если он заполнен, иначе берет post_content.
	 * HTML удаляется перед обрезкой, чтобы в превью не попадала разметка.
	 *
	 * @param \WP_Post $post Запись статьи WordPress.
	 *
	 * @return string Обрезанный текст статьи.
	 */
	private function getArticleExcerpt( \WP_Post $post ): string {
		$excerpt = has_excerpt( $post->ID ) ? get_the_excerpt( $post->ID ) : $post->post_content;

		$excerpt = wp_strip_all_tags( $excerpt );

		return wp_trim_words( $excerpt, 24, '...' );
	}
}
