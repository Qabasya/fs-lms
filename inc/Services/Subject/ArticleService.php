<?php

declare(strict_types=1);

namespace Inc\Services\Subject;

use Inc\DTO\Subject\TermViewDTO;
use Inc\Managers\Wp\TermManager;
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
	 * Возвращает последние опубликованные статьи предмета (блок «Рекомендуемые»).
	 *
	 * @param string $subject_key Ключ предмета.
	 *
	 * @return array Список статей, свежие первыми.
	 */
	public function getLatestArticles( string $subject_key ): array {
		if ( '' === $subject_key ) {
			return array();
		}

		$post_type = PostTypeResolver::articles( $subject_key );
		$posts     = $this->article_repository->findLatest( $post_type );

		return $this->formatArticlePosts( $posts );
	}

	/**
	 * Статьи для сайдбара «Все задания».
	 *
	 * Правило подбора:
	 *   - тип задания не выбран → случайные статьи предмета;
	 *   - выбран → статьи выбранных типов, свежие первыми;
	 *   - если их меньше лимита → добор последними опубликованными (без повторов).
	 *
	 * @param string $subject_key Ключ предмета.
	 * @param array  $type_slugs  Слаги выбранных типов задания (термины {key}_task_number).
	 * @param int    $limit       Сколько статей показать.
	 *
	 * @return array Список статей для шаблона.
	 */
	public function getSidebarArticles( string $subject_key, array $type_slugs, int $limit = 4 ): array {
		if ( '' === $subject_key || $limit < 1 ) {
			return array();
		}

		$post_type = PostTypeResolver::articles( $subject_key );

		if ( empty( $type_slugs ) ) {
			return $this->formatArticlePosts( $this->article_repository->findRandom( $post_type, $limit ) );
		}

		$posts = $this->article_repository->findByTermSlugs(
			$post_type,
			PostTypeResolver::getTaskTaxonomy( $subject_key ),
			$type_slugs,
			$limit
		);

		if ( count( $posts ) < $limit ) {
			$posts = array_merge(
				$posts,
				$this->article_repository->findLatest(
					$post_type,
					$limit - count( $posts ),
					array_map( static fn( \WP_Post $post ) => $post->ID, $posts )
				)
			);
		}

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
				// Миниатюра: сайдбар обрезает её в квадрат, карусель — в широкую
				// шапку карточки. Размера medium хватает обоим; пусто — блока нет.
				'thumbnail'   => (string) get_the_post_thumbnail_url( $post->ID, 'medium' ),
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