<?php

declare(strict_types=1);

namespace Inc\Services\Subject;

use Inc\DTO\Article\AdjacentArticleDTO;
use Inc\DTO\Article\ArticleCardDTO;
use Inc\DTO\Article\ArticleNavigationDTO;
use Inc\DTO\Subject\TermViewDTO;
use Inc\Enums\Wp\PostMetaName;
use Inc\Enums\Wp\TransientKey;
use Inc\Managers\Wp\PostManager;
use Inc\Managers\Wp\TermManager;
use Inc\Managers\Wp\TransientManager;
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

	/** Сколько статей отдаёт блок «Рекомендуемые» — размер выборки по умолчанию. */
	private const LATEST_LIMIT = 6;

	/** Скорость чтения для оценки времени в карточке каталога. */
	private const WORDS_PER_MINUTE = 180;

	/** Сколько живёт кеш каталога учебника (правки статей сбрасывают его раньше). */
	private const CATALOG_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * Версия формата кеша каталога. Поднять при изменении ArticleCardDTO:
	 * распакованный старый набор остался бы без новых полей.
	 */
	private const CATALOG_FORMAT = 1;

	/**
	 * @param ArticleRepository $article_repository Репозиторий статей.
	 * @param TermManager       $term_manager       Менеджер терминов таксономии.
	 * @param PostManager       $post_manager       Менеджер записей (мета статьи).
	 * @param TransientManager  $transients         Кеш каталога учебника.
	 */
	public function __construct(
		private readonly ArticleRepository $article_repository,
		private readonly TermManager $term_manager,
		private readonly PostManager $post_manager,
		private readonly TransientManager $transients,
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
	 * Возвращает последние опубликованные статьи предмета (блок «Рекомендуемые»,
	 * витрина учебника).
	 *
	 * @param string $subject_key Ключ предмета.
	 * @param int    $limit       Сколько статей вернуть.
	 *
	 * @return array Список статей, свежие первыми.
	 */
	public function getLatestArticles( string $subject_key, int $limit = self::LATEST_LIMIT ): array {
		if ( '' === $subject_key || $limit < 1 ) {
			return array();
		}

		$post_type = PostTypeResolver::articles( $subject_key );
		$posts     = $this->article_repository->findLatest( $post_type, $limit );

		return $this->formatArticlePosts( $posts );
	}

	/**
	 * Карточки всех опубликованных статей предмета — каталог учебника.
	 *
	 * Термины проставляются только для перечисленных таксономий: по ним
	 * фильтрует каталог, лишние в разметку не выходят.
	 *
	 * @param string   $subject_key Ключ предмета.
	 * @param string[] $taxonomies  Слаги таксономий, попадающих в карточку.
	 *
	 * @return ArticleCardDTO[] Карточки в порядке чтения.
	 */
	public function getCatalogCards( string $subject_key, array $taxonomies ): array {
		if ( '' === $subject_key ) {
			return array();
		}

		$cached = $this->cachedCatalog( $subject_key, $taxonomies );

		if ( null !== $cached ) {
			return $cached;
		}

		$cards = $this->buildCatalogCards( $subject_key, $taxonomies );

		$this->transients->set(
			TransientKey::ArticleCatalog,
			$subject_key,
			array(
				'format'     => self::CATALOG_FORMAT,
				'taxonomies' => $taxonomies,
				'cards'      => $cards,
			),
			self::CATALOG_TTL
		);

		return $cards;
	}

	/**
	 * Кешированные карточки каталога; null — кеша нет или он не подходит.
	 *
	 * Набор таксономий входит в кеш: админ мог включить таксономии флаг
	 * «использовать в статьях», и в старых карточках её терминов ещё нет —
	 * фильтр по ней не нашёл бы ни одной статьи.
	 *
	 * @param string   $subject_key Ключ предмета.
	 * @param string[] $taxonomies  Слаги таксономий, попадающих в карточку.
	 *
	 * @return ArticleCardDTO[]|null
	 */
	private function cachedCatalog( string $subject_key, array $taxonomies ): ?array {
		$cached = $this->transients->get( TransientKey::ArticleCatalog, $subject_key );

		if ( ! is_array( $cached ) || ! isset( $cached['cards'] ) || ! is_array( $cached['cards'] ) ) {
			return null;
		}

		if ( self::CATALOG_FORMAT !== ( $cached['format'] ?? 0 ) ) {
			return null;
		}

		if ( ( $cached['taxonomies'] ?? array() ) !== $taxonomies ) {
			return null;
		}

		return $cached['cards'];
	}

	/**
	 * Собирает карточки каталога из записей предмета.
	 *
	 * @param string   $subject_key Ключ предмета.
	 * @param string[] $taxonomies  Слаги таксономий, попадающих в карточку.
	 *
	 * @return ArticleCardDTO[]
	 */
	private function buildCatalogCards( string $subject_key, array $taxonomies ): array {
		$posts = $this->article_repository->findAllPublished( PostTypeResolver::articles( $subject_key ) );

		// Описание карточки читается метой у каждой записи — один запрос на всех.
		$this->post_manager->primeMetaCache(
			array_map( static fn( \WP_Post $post ): int => $post->ID, $posts )
		);

		return array_map(
			fn( \WP_Post $post ): ArticleCardDTO => new ArticleCardDTO(
				id:        $post->ID,
				title:     (string) get_the_title( $post->ID ),
				url:       (string) get_permalink( $post->ID ),
				excerpt:   $this->getArticleExcerpt( $post ),
				thumbnail: $this->post_manager->getThumbnailUrl( $post->ID, 'medium' ),
				minutes:   $this->readingMinutes( $post->post_content ),
				terms:     $this->cardTerms( $post->ID, $taxonomies ),
			),
			$posts
		);
	}

	/**
	 * Слаги терминов статьи по каждой из таксономий.
	 *
	 * @param int      $post_id    ID статьи.
	 * @param string[] $taxonomies Слаги таксономий.
	 *
	 * @return array<string, string[]> [taxonomy => term_slugs]
	 */
	private function cardTerms( int $post_id, array $taxonomies ): array {
		$terms = array();

		foreach ( $taxonomies as $taxonomy ) {
			$slugs = array_map(
				static fn( \WP_Term $term ): string => $term->slug,
				$this->term_manager->getPostTerms( $post_id, $taxonomy )
			);

			if ( ! empty( $slugs ) ) {
				$terms[ $taxonomy ] = array_values( $slugs );
			}
		}

		return $terms;
	}

	/**
	 * Время чтения статьи в минутах — по числу слов текста.
	 *
	 * Считаем регуляркой, а не str_word_count(): та ломается на кириллице.
	 * Скорость — 180 слов в минуту, нижняя граница — одна минута: «0 мин»
	 * в карточке выглядело бы ошибкой.
	 *
	 * @param string $content Текст статьи (HTML).
	 *
	 * @return int Минуты чтения; 0 — текста нет.
	 */
	private function readingMinutes( string $content ): int {
		$text  = wp_strip_all_tags( $content );
		$words = preg_match_all( '/[\p{L}\p{N}]+/u', $text );

		if ( ! $words ) {
			return 0;
		}

		return max( 1, (int) round( $words / self::WORDS_PER_MINUTE ) );
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
	 * Собирает навигацию страницы статьи по серии одного номера задания.
	 *
	 * Серия — все опубликованные статьи с тем же термином {key}_task_number,
	 * в порядке чтения. Из одного списка берутся и соседи, и позиция: считать их
	 * разными запросами нельзя — разъедутся. Стабильность самого порядка держит
	 * вторичная сортировка по ID в ArticleRepository::findAllInTerm().
	 *
	 * @param string $subject_key  Ключ предмета.
	 * @param int    $current_id   ID открытой статьи.
	 * @param string $articles_url Ссылка на учебник предмета.
	 *
	 * @return ArticleNavigationDTO Пустой DTO — серии нет, блок не рендерится.
	 */
	public function getNavigation( string $subject_key, int $current_id, string $articles_url ): ArticleNavigationDTO {
		if ( '' === $subject_key || $current_id < 1 ) {
			return new ArticleNavigationDTO();
		}

		$taxonomy = PostTypeResolver::getTaskTaxonomy( $subject_key );
		$terms    = $this->term_manager->getPostTerms( $current_id, $taxonomy );
		$term     = $terms[0] ?? null;

		// Номер задания у статьи не проставлен: множества «статей того же типа»
		// не существует, переключать и считать нечего.
		if ( ! $term instanceof \WP_Term ) {
			return new ArticleNavigationDTO();
		}

		$posts = $this->article_repository->findAllInTerm(
			PostTypeResolver::articles( $subject_key ),
			(int) $term->term_id,
			$taxonomy
		);

		$ids   = array_map( static fn( \WP_Post $post ): int => (int) $post->ID, $posts );
		$index = array_search( $current_id, $ids, true );

		// Текущей статьи нет в выборке — например, она ещё черновик.
		if ( ! is_int( $index ) ) {
			return new ArticleNavigationDTO();
		}

		$total = count( $posts );

		if ( $total < ArticleNavigationDTO::MIN_SERIES ) {
			return new ArticleNavigationDTO();
		}

		return new ArticleNavigationDTO(
			prev:         $this->adjacentArticle( $posts, $index - 1, $total - 1 ),
			next:         $this->adjacentArticle( $posts, $index + 1, 0 ),
			articles_url: $articles_url,
			position:     $index + 1,
			total:        $total,
		);
	}

	/**
	 * Соседняя статья серии; за краем списка сторона замыкается на $wrap_index.
	 *
	 * @param \WP_Post[] $posts      Статьи серии в порядке чтения.
	 * @param int        $index      Позиция соседа.
	 * @param int        $wrap_index Позиция, к которой сторона замыкается за краем.
	 *
	 * @return AdjacentArticleDTO|null Null — на месте замыкания не оказалось записи.
	 */
	private function adjacentArticle( array $posts, int $index, int $wrap_index ): ?AdjacentArticleDTO {
		$wrapped = ! isset( $posts[ $index ] );
		$post    = $posts[ $index ] ?? $posts[ $wrap_index ] ?? null;

		if ( ! $post instanceof \WP_Post ) {
			return null;
		}

		return new AdjacentArticleDTO(
			title:       get_the_title( $post ),
			url:         (string) get_permalink( $post ),
			description: $this->getArticleExcerpt( $post ),
			thumbnail:   $this->post_manager->getThumbnailUrl( $post->ID, 'medium' ),
			wrapped:     $wrapped,
		);
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

		// Описание карточки читается метой у каждой записи — прогреваем кэш одним запросом.
		$this->post_manager->primeMetaCache(
			array_map(
				static fn( $post ) => $post instanceof \WP_Post ? $post->ID : 0,
				$posts
			)
		);

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
	 * Заполнено «Краткое описание» ({@see PostMetaName::ArticleDescription}) —
	 * показываем его целиком: автор писал именно текст карточки. Пусто — фолбэк
	 * на ручной excerpt, иначе на начало текста статьи; карточка обрезает его
	 * до трёх строк (`line-clamp(3)` в `_carousel.scss`).
	 * HTML удаляется перед обрезкой, чтобы в превью не попадала разметка.
	 *
	 * @param \WP_Post $post Запись статьи WordPress.
	 *
	 * @return string Текст карточки статьи.
	 */
	private function getArticleExcerpt( \WP_Post $post ): string {
		$description = $this->post_manager->getMeta( $post->ID, PostMetaName::ArticleDescription->value );

		if ( is_string( $description ) && '' !== trim( $description ) ) {
			return trim( $description );
		}

		$excerpt = has_excerpt( $post->ID ) ? get_the_excerpt( $post->ID ) : $post->post_content;

		$excerpt = wp_strip_all_tags( $excerpt );

		return wp_trim_words( $excerpt, 24, '...' );
	}
}
