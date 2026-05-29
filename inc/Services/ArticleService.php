<?php

declare(strict_types=1);

namespace Inc\Services;

use Inc\DTO\TermViewDTO;
use Inc\Managers\TermManager;
use Inc\Repositories\OptionsRepositories\ArticleRepository;

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

	/**
	 * @param ArticleRepository $article_repository Репозиторий статей.
	 * @param TermManager       $term_manager       Менеджер терминов таксономии.
	 */
	public function __construct(
		private readonly ArticleRepository $article_repository,
		private readonly TermManager $term_manager,
	) {}

	/**
	 * Возвращает статьи предмета, связанные с типом задания.
	 *
	 * @param string           $subject_key       Ключ предмета.
	 * @param TermViewDTO|null $current_task_type DTO текущего типа задания.
	 *
	 * @return array Список связанных статей.
	 */
	public function getRelatedArticles( string $subject_key, ?TermViewDTO $current_task_type ): array {
		if ( '' === $subject_key || ! $current_task_type ) {
			return array();
		}

		$post_type = PostTypeResolver::articles( $subject_key );

		$posts = $this->article_repository->findRelated(
			$post_type,
			$current_task_type->id,
			$current_task_type->taxonomy
		);

		return $this->formatArticlePosts( $posts );
	}

	/**
	 * Возвращает случайные статьи предмета.
	 *
	 * @param string $subject_key Ключ предмета.
	 *
	 * @return array Список случайных статей.
	 */
	public function getRandomArticles( string $subject_key ): array {
		if ( '' === $subject_key ) {
			return array();
		}

		$post_type = PostTypeResolver::articles( $subject_key );
		$posts     = $this->article_repository->findRandom( $post_type );

		return $this->formatArticlePosts( $posts );
	}

	/**
	 * Приводит записи статей к единому формату для шаблона.
	 *
	 * @param array $posts Массив WP_Post объектов.
	 *
	 * @return array Список статей.
	 */
	private function formatArticlePosts( array $posts ): array {
		$articles = array();

		foreach ( $posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}

			$subject_key = PostTypeResolver::subjectFromArticlePostType( $post->post_type );
			$task_number = '';

			if ( $subject_key ) {
				$terms = $this->term_manager->getPostTerms(
					$post->ID,
					PostTypeResolver::getTaskTaxonomy( $subject_key )
				);

				if ( ! empty( $terms ) ) {
					$task_number = $terms[0]->name ?? '';
				}
			}

			$articles[] = array(
				'id'          => $post->ID,
				'title'       => get_the_title( $post->ID ),
				'url'         => get_permalink( $post->ID ),
				'excerpt'     => $this->getArticleExcerpt( $post ),
				'task_number' => $task_number,
			);
		}

		return $articles;
	}

	/**
	 * Возвращает короткий текст статьи для карточки на frontend.
	 *
	 * Использует ручной excerpt, если он заполнен, иначе берёт post_content.
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