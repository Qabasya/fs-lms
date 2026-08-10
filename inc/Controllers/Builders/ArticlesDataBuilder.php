<?php

declare( strict_types=1 );

namespace Inc\Controllers\Builders;

use Inc\DTO\Article\ArticleCardDTO;
use Inc\DTO\Article\ArticleSectionDTO;
use Inc\DTO\Article\ArticlesPageDTO;
use Inc\DTO\Subject\TaxonomyDataDTO;
use Inc\Managers\Wp\PostManager;
use Inc\Managers\Wp\TermManager;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Repositories\OptionsRepositories\TaxonomyRepository;
use Inc\Services\Subject\ArticleService;
use Inc\Services\Subject\FilterGroupService;
use Inc\Services\Subject\PostTypeResolver;
use Inc\Services\Subject\SubjectPagesService;

/**
 * Class ArticlesDataBuilder
 *
 * Строитель данных раздела «Учебник» лендинга предмета.
 *
 * Каталог отдаётся целиком одной страницей: секции по номерам заданий, группы
 * фильтров сайдбара и блок-призыв в тренажёр. Фильтрация и поиск идут на
 * клиенте (`article-catalog.js`) — статей у предмета на порядок меньше, чем
 * заданий, и дробить их пагинацией незачем.
 *
 * @package Inc\Controllers\Builders
 */
readonly class ArticlesDataBuilder {

	/** Заголовок секции для статей без номера задания. */
	private const OTHER_LABEL = 'Другие материалы';

	/** Якорь той же секции: слага термина у неё нет. */
	private const OTHER_ANCHOR = 'other';

	public function __construct(
		private SubjectRepository   $subject_repository,
		private TaxonomyRepository  $taxonomy_repository,
		private PostManager         $post_manager,
		private TermManager         $term_manager,
		private ArticleService      $article_service,
		private BreadcrumbsBuilder  $breadcrumbs_builder,
		private SubjectPagesService $subject_pages,
		private FilterGroupService  $filter_groups,
	) {}

	/**
	 * Полный DTO каталога учебника.
	 *
	 * @param string $subject_key Ключ предмета.
	 *
	 * @return ArticlesPageDTO
	 */
	public function getPageData( string $subject_key ): ArticlesPageDTO {
		$subject      = $this->subject_repository->getByKey( $subject_key );
		$subject_name = $subject?->name ?? $subject_key;

		$links      = $this->subject_pages->links( $subject_key );
		$number_tax = PostTypeResolver::getTaskTaxonomy( $subject_key );
		$taxonomies = $this->articleTaxonomies( $subject_key );

		$slugs = array_merge(
			array( $number_tax ),
			array_map( static fn( TaxonomyDataDTO $taxonomy ): string => $taxonomy->slug, $taxonomies )
		);

		$cards = $this->article_service->getCatalogCards( $subject_key, $slugs );

		// Считаем банк только когда есть куда вести: без тренажёра блок-призыв
		// сайдбара не выйдет в любом случае.
		$tasks_total = '' !== $links->trainer
			? $this->post_manager->countPublished( PostTypeResolver::tasks( $subject_key ) )
			: 0;

		return new ArticlesPageDTO(
			breadcrumbs: $this->breadcrumbs_builder->forArticles( $subject_name, $links ),
			filters:     $this->buildFilters( $subject_key, $number_tax, $taxonomies ),
			sections:    $this->buildSections( $cards, $number_tax ),
			total:       count( $cards ),
			trainer_url: $links->trainer,
			tasks_total: $tasks_total,
		);
	}

	/**
	 * Таксономии предмета, видимые посетителю учебника.
	 *
	 * Обязательная = проставлена у каждой записи, по ней можно фильтровать;
	 * флаг «использовать в статьях» говорит, что она вообще привязана к CPT
	 * статей. Служебные пометки автора (необязательные) на фронт не выходят.
	 *
	 * @param string $subject_key Ключ предмета.
	 *
	 * @return TaxonomyDataDTO[]
	 */
	private function articleTaxonomies( string $subject_key ): array {
		return array_values(
			array_filter(
				$this->taxonomy_repository->getBySubject( $subject_key ),
				static fn( TaxonomyDataDTO $taxonomy ): bool => $taxonomy->is_required && $taxonomy->use_in_articles
			)
		);
	}

	/**
	 * Секции каталога: по одной на номер задания, в порядке номеров.
	 *
	 * Пустые номера пропускаются, статьи без номера собираются в хвостовую
	 * секцию — иначе они выпали бы из каталога совсем.
	 *
	 * @param ArticleCardDTO[] $cards      Карточки каталога.
	 * @param string           $number_tax Таксономия номеров заданий.
	 *
	 * @return ArticleSectionDTO[]
	 */
	private function buildSections( array $cards, string $number_tax ): array {
		$by_slug = array();

		foreach ( $cards as $card ) {
			$slug               = $card->terms[ $number_tax ][0] ?? self::OTHER_ANCHOR;
			$by_slug[ $slug ][] = $card;
		}

		$sections = array();

		foreach ( $this->term_manager->getAll( $number_tax ) as $term ) {
			if ( empty( $by_slug[ $term->slug ] ) ) {
				continue;
			}

			$sections[] = new ArticleSectionDTO(
				label:    $this->termLabel( $term ),
				anchor:   $term->slug,
				articles: $by_slug[ $term->slug ],
			);
		}

		if ( ! empty( $by_slug[ self::OTHER_ANCHOR ] ) ) {
			$sections[] = new ArticleSectionDTO(
				label:    self::OTHER_LABEL,
				anchor:   self::OTHER_ANCHOR,
				articles: $by_slug[ self::OTHER_ANCHOR ],
			);
		}

		return $sections;
	}

	/**
	 * Подпись номера задания: описание термина, если автор его заполнил
	 * («Задание №19–21» для сдвоенных номеров), иначе номер по шаблону.
	 *
	 * @param \WP_Term $term Термин таксономии номеров.
	 *
	 * @return string
	 */
	private function termLabel( \WP_Term $term ): string {
		$description = trim( (string) $term->description );

		return '' !== $description ? $description : 'Задание №' . $term->name;
	}

	/**
	 * Группы фильтров сайдбара: номер задания + таксономии статей.
	 *
	 * Формат групп общий с тренажёром (FilterGroupService) — сайдбар и его
	 * JS-компонент у страниц одни. Счётчики здесь считаются по всему банку
	 * статей: срез под текущий выбор пересчитывает клиент (`article-catalog.js`),
	 * потому что каталог отрисован целиком и ходить за фасетами некуда.
	 *
	 * @param string            $subject_key Ключ предмета.
	 * @param string            $number_tax  Таксономия номеров заданий.
	 * @param TaxonomyDataDTO[] $taxonomies  Таксономии статей предмета.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function buildFilters( string $subject_key, string $number_tax, array $taxonomies ): array {
		$post_type = PostTypeResolver::articles( $subject_key );
		$sources   = array( array( $number_tax, 'Тип задания', true ) );

		foreach ( $taxonomies as $taxonomy ) {
			$sources[] = array( $taxonomy->slug, $taxonomy->name, false );
		}

		$groups = array();

		foreach ( $sources as [ $tax_slug, $name, $is_type ] ) {
			$counts = $this->term_manager->countPostsByType( $tax_slug, $post_type );
			$terms  = $this->filter_groups->options( $tax_slug, $counts, array(), $tax_slug === $number_tax );
			$group  = $this->filter_groups->group( $tax_slug, $name, $terms, $is_type );

			// Ни одной статьи с терминами этой таксономии — фильтровать нечем.
			if ( 0 === $group['available'] ) {
				continue;
			}

			$groups[] = $group;
		}

		return $groups;
	}
}