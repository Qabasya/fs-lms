<?php

declare( strict_types=1 );

namespace Inc\Services\Seo;

use Inc\Enums\Wp\PostMetaName;
use Inc\Managers\Wp\PostManager;
use Inc\Managers\Wp\TermManager;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Services\Subject\PostTypeResolver;
use Inc\Services\Subject\SubjectPagesService;

/**
 * Class ContentSeoService
 *
 * Данные для поисковых тегов публичных страниц банков: заголовок, описание и
 * микроразметка задания и статьи.
 *
 * @package Inc\Services\Seo
 *
 * ### Основные обязанности:
 *
 * 1. **Сбор исходных данных** — предмет, номер задания, условие, краткое описание статьи.
 * 2. **Делегирование текста** — формулировки собирает {@see ContentSeoTextBuilder}.
 * 3. **Микроразметка** — `LearningResource` для задания и `BreadcrumbList` для обоих типов.
 *
 * ### Архитектурная роль:
 *
 * Не знает ни о Rank Math, ни о выводе в `<head>`: отдаёт строки и массивы, а хуки
 * регистрирует {@see \Inc\Controllers\Seo\ContentSeoController}.
 */
readonly class ContentSeoService {

	/** Сколько знаков условия читаем в описание: с запасом, обрезает построитель. */
	private const CONDITION_SOURCE_LENGTH = 400;

	/**
	 * @param SubjectRepository     $subjects Репозиторий предметов.
	 * @param PostManager           $posts    Менеджер записей.
	 * @param TermManager           $terms    Менеджер терминов.
	 * @param SubjectPagesService   $pages    Страницы лендинга предмета (ссылки для крошек).
	 * @param ContentSeoTextBuilder $texts    Построитель текстов.
	 */
	public function __construct(
		private SubjectRepository $subjects,
		private PostManager $posts,
		private TermManager $terms,
		private SubjectPagesService $pages,
		private ContentSeoTextBuilder $texts,
	) {}

	/**
	 * Относится ли запись к заданиям или статьям предметов.
	 *
	 * @param \WP_Post $post Запись.
	 *
	 * @return bool
	 */
	public function isManaged( \WP_Post $post ): bool {
		return PostTypeResolver::isTaskPostType( $post->post_type )
			|| PostTypeResolver::isArticlePostType( $post->post_type );
	}

	/**
	 * Заголовок задания без названия сайта; '' — запись не задание.
	 *
	 * @param \WP_Post $post Запись.
	 *
	 * @return string
	 */
	public function taskTitle( \WP_Post $post ): string {
		if ( ! PostTypeResolver::isTaskPostType( $post->post_type ) ) {
			return '';
		}

		$ctx = $this->taskContext( $post );

		return $this->texts->taskTitle( $ctx['exam'], $ctx['task'], $post->post_title );
	}

	/**
	 * Описание задания; '' — запись не задание.
	 *
	 * @param \WP_Post $post Запись.
	 *
	 * @return string
	 */
	public function taskDescription( \WP_Post $post ): string {
		if ( ! PostTypeResolver::isTaskPostType( $post->post_type ) ) {
			return '';
		}

		$ctx = $this->taskContext( $post );

		return $this->texts->taskDescription( $ctx['exam'], $ctx['task'], $ctx['problem'], $this->conditionText( $post ) );
	}

	/**
	 * Описание статьи: «Краткое описание» автора, иначе начало текста; '' — запись не статья.
	 *
	 * @param \WP_Post $post Запись.
	 *
	 * @return string
	 */
	public function articleDescription( \WP_Post $post ): string {
		if ( ! PostTypeResolver::isArticlePostType( $post->post_type ) ) {
			return '';
		}

		$short = $this->posts->getMeta( $post->ID, PostMetaName::ArticleDescription->value );
		$text  = wp_strip_all_tags( strip_shortcodes( $post->post_content ), true );

		return $this->texts->articleDescription( is_string( $short ) ? $short : '', $text );
	}

	/**
	 * Граф микроразметки страницы банка: крошки и, для задания, учебный материал.
	 *
	 * @param \WP_Post $post             Запись задания или статьи.
	 * @param bool     $with_breadcrumbs Выводить ли `BreadcrumbList` (выключаем, если его уже даёт SEO-плагин).
	 *
	 * @return array<int, array<string, mixed>> Узлы `@graph`; пусто — запись не из банков.
	 */
	public function schemaGraph( \WP_Post $post, bool $with_breadcrumbs ): array {
		if ( ! $this->isManaged( $post ) ) {
			return array();
		}

		$url   = (string) get_permalink( $post );
		$graph = array();

		if ( PostTypeResolver::isTaskPostType( $post->post_type ) ) {
			$graph[] = $this->learningResource( $post, $url );
		}

		if ( $with_breadcrumbs ) {
			$crumbs = $this->breadcrumbs( $post, $url );

			if ( count( $crumbs ) > 1 ) {
				$graph[] = $this->breadcrumbList( $crumbs, $url );
			}
		}

		return $graph;
	}

	/**
	 * Исходные данные задания: название экзамена, номер задания, номер задачи.
	 *
	 * @param \WP_Post $post Запись задания.
	 *
	 * @return array{exam: string, task: string, problem: string}
	 */
	private function taskContext( \WP_Post $post ): array {
		$key     = PostTypeResolver::subjectFromTaskPostType( $post->post_type );
		$subject = $this->subjects->getByKey( $key );
		$term    = $this->terms->getPostTerms( $post->ID, PostTypeResolver::getTaskTaxonomy( $key ) )[0] ?? null;

		return array(
			'exam'    => $this->texts->examLabel( $subject ? $subject->name : $key ),
			'task'    => $term instanceof \WP_Term ? $this->texts->taskNumber( $term->name ) : '',
			'problem' => $this->texts->problemNumber( $post->post_title, $post->post_name ),
		);
	}

	/**
	 * Условие задания простым текстом.
	 *
	 * Читаем сырые поля условия, а не `getCombinedCondition()`: тот прогоняет
	 * `the_content` и тянет разметку, а в `<head>` нужен чистый текст.
	 *
	 * @param \WP_Post $post Запись задания.
	 *
	 * @return string
	 */
	private function conditionText( \WP_Post $post ): string {
		$meta = $this->posts->taskMeta( $post->ID );
		ksort( $meta );

		$parts = array();

		foreach ( $meta as $field => $value ) {
			if ( is_string( $value ) && str_contains( (string) $field, '_condition' ) ) {
				$parts[] = wp_strip_all_tags( strip_shortcodes( $value ), true );
			}
		}

		return mb_substr( implode( ' ', $parts ), 0, self::CONDITION_SOURCE_LENGTH );
	}

	/**
	 * Узел `LearningResource` задания.
	 *
	 * @param \WP_Post $post Запись задания.
	 * @param string   $url  Постоянная ссылка записи.
	 *
	 * @return array<string, mixed>
	 */
	private function learningResource( \WP_Post $post, string $url ): array {
		$ctx = $this->taskContext( $post );

		return array(
			'@type'                => 'LearningResource',
			'@id'                  => $url . '#task',
			'url'                  => $url,
			'name'                 => $this->texts->taskTitle( $ctx['exam'], $ctx['task'], $post->post_title ),
			'description'          => $this->taskDescription( $post ),
			'learningResourceType' => 'Задача с решением',
			'educationalUse'       => 'practice',
			'about'                => $ctx['exam'],
			'inLanguage'           => 'ru',
			'datePublished'        => get_the_date( 'c', $post ),
			'dateModified'         => get_the_modified_date( 'c', $post ),
		);
	}

	/**
	 * Хлебные крошки: предмет → раздел → страница.
	 *
	 * @param \WP_Post $post Запись задания или статьи.
	 * @param string   $url  Постоянная ссылка записи.
	 *
	 * @return array<int, array{name: string, url: string}>
	 */
	private function breadcrumbs( \WP_Post $post, string $url ): array {
		$is_task = PostTypeResolver::isTaskPostType( $post->post_type );
		$key     = $is_task
			? PostTypeResolver::subjectFromTaskPostType( $post->post_type )
			: PostTypeResolver::subjectFromArticlePostType( $post->post_type );
		$subject = $this->subjects->getByKey( $key );
		$links   = $this->pages->links( $key );
		$crumbs  = array();

		if ( $subject && '' !== $links->subject ) {
			$crumbs[] = array(
				'name' => $subject->name,
				'url'  => $links->subject,
			);
		}

		$section_url = $is_task ? $links->trainer : $links->articles;

		if ( '' !== $section_url ) {
			$crumbs[] = array(
				'name' => $is_task ? 'Тренажёр' : 'Учебник',
				'url'  => $section_url,
			);
		}

		$crumbs[] = array(
			'name' => wp_strip_all_tags( $post->post_title ),
			'url'  => $url,
		);

		return $crumbs;
	}

	/**
	 * Узел `BreadcrumbList`.
	 *
	 * @param array<int, array{name: string, url: string}> $crumbs Крошки по порядку.
	 * @param string                                       $url    Ссылка страницы.
	 *
	 * @return array<string, mixed>
	 */
	private function breadcrumbList( array $crumbs, string $url ): array {
		$items = array();

		foreach ( array_values( $crumbs ) as $index => $crumb ) {
			$items[] = array(
				'@type'    => 'ListItem',
				'position' => $index + 1,
				'name'     => $crumb['name'],
				'item'     => $crumb['url'],
			);
		}

		return array(
			'@type'           => 'BreadcrumbList',
			'@id'             => $url . '#breadcrumb',
			'itemListElement' => $items,
		);
	}
}
