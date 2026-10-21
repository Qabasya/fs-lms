<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Seo;

use Inc\Core\BaseController;
use Inc\Managers\Wp\PostManager;
use Inc\Services\Seo\ContentSeoService;

/**
 * Class ContentSeoCallbacks
 *
 * Обработчики поисковых тегов публичных страниц банков. Хуки регистрирует
 * {@see \Inc\Controllers\Seo\ContentSeoController}, тексты и данные собирает
 * {@see ContentSeoService}.
 *
 * @package Inc\Callbacks\Seo
 *
 * ### Что делает
 *
 * - Подменяет `title` и `description` заданий и описание статей, пока редактор не задал свои
 *   в поле SEO-плагина (ручное значение всегда главнее).
 * - Закрывает от индекса страницы разделов с параметрами фильтров: это дубли учебника
 *   и тренажёра с тем же содержимым.
 * - Печатает микроразметку заданий и статей.
 * - Добавляет `Clean-param` в robots.txt — директиву Яндекса, которую редактор Rank Math
 *   не принимает при сохранении, а через фильтр она проходит.
 */
class ContentSeoCallbacks extends BaseController {

	/** Параметры адреса, по которым страница раздела превращается в дубль. */
	private const FILTER_PARAMS = array( 'filters', 'task_type' );

	/** Метки, которые не меняют содержимое страницы: Яндекс склеивает такие адреса. */
	private const CLEAN_PARAMS = array( 'task_type', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'yclid', 'ysclid' );

	/** Постмета Rank Math: заголовок, заданный редактором вручную. */
	private const MANUAL_TITLE_META = 'rank_math_title';

	/** Постмета Rank Math: описание, заданное редактором вручную. */
	private const MANUAL_DESCRIPTION_META = 'rank_math_description';

	/**
	 * @param ContentSeoService $seo   Сервис данных поисковых тегов.
	 * @param PostManager       $posts Менеджер записей.
	 */
	public function __construct(
		private readonly ContentSeoService $seo,
		private readonly PostManager $posts,
	) {
		parent::__construct();
	}

	/**
	 * Заголовок задания вместо рабочего имени записи.
	 *
	 * Фильтр Rank Math отдаёт уже собранный заголовок с названием сайта, поэтому
	 * хвост «разделитель + сайт» приклеиваем здесь же.
	 *
	 * @param string $title Заголовок от SEO-плагина.
	 *
	 * @return string
	 */
	public function filterTitle( string $title ): string {
		$post = $this->currentPost();

		if ( null === $post || $this->hasManual( $post, self::MANUAL_TITLE_META ) ) {
			return $title;
		}

		$own = $this->seo->taskTitle( $post );

		return '' === $own ? $title : sprintf( '%s %s %s', $own, $this->separator(), get_bloginfo( 'name' ) );
	}

	/**
	 * Описание задания и статьи.
	 *
	 * @param string $description Описание от SEO-плагина.
	 *
	 * @return string
	 */
	public function filterDescription( string $description ): string {
		$post = $this->currentPost();

		if ( null === $post || $this->hasManual( $post, self::MANUAL_DESCRIPTION_META ) ) {
			return $description;
		}

		$own = $this->seo->taskDescription( $post );

		if ( '' === $own ) {
			$own = $this->seo->articleDescription( $post );
		}

		return '' === $own ? $description : $own;
	}

	/**
	 * Страницы с параметрами фильтров — `noindex, follow`.
	 *
	 * @param array<string, string> $robots Директивы SEO-плагина.
	 *
	 * @return array<string, string>
	 */
	public function filterRobots( array $robots ): array {
		if ( $this->isFilteredRequest() ) {
			$robots['index'] = 'noindex';
		}

		return $robots;
	}

	/**
	 * Микроразметка задания и статьи в `<head>`.
	 *
	 * Печатаем сами, а не через фильтр SEO-плагина: у типов записей с отключённой схемой
	 * Rank Math не выводит ничего, и фильтр бы не сработал.
	 *
	 * @return void
	 */
	public function printSchema(): void {
		$post = $this->currentPost();

		if ( null === $post ) {
			return;
		}

		$graph = $this->seo->schemaGraph( $post, ! $this->pluginHasBreadcrumbs() );

		if ( array() === $graph ) {
			return;
		}

		printf(
			'<script type="application/ld+json">%s</script>' . "\n",
			wp_json_encode(
				array(
					'@context' => 'https://schema.org',
					'@graph'   => $graph,
				),
				JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG
			)
		);
	}

	/**
	 * `Clean-param` для Яндекса в конец виртуального robots.txt.
	 *
	 * @param string $output Содержимое robots.txt.
	 * @param bool   $is_public Открыт ли сайт для индексации.
	 *
	 * @return string
	 */
	public function filterRobotsTxt( string $output, bool $is_public ): string {
		if ( ! $is_public || str_contains( $output, 'Clean-param' ) ) {
			return $output;
		}

		return rtrim( $output ) . "\n\nClean-param: " . implode( '&', self::CLEAN_PARAMS ) . "\n";
	}

	/**
	 * Открытая запись задания или статьи; null — другая страница.
	 *
	 * @return \WP_Post|null
	 */
	private function currentPost(): ?\WP_Post {
		if ( is_admin() || ! is_singular() ) {
			return null;
		}

		$post = get_queried_object();

		return $post instanceof \WP_Post && $this->seo->isManaged( $post ) ? $post : null;
	}

	/**
	 * Задал ли редактор своё значение в поле SEO-плагина.
	 *
	 * @param \WP_Post $post Запись.
	 * @param string   $meta Ключ постметы.
	 *
	 * @return bool
	 */
	private function hasManual( \WP_Post $post, string $meta ): bool {
		$value = $this->posts->getMeta( $post->ID, $meta );

		return is_string( $value ) && '' !== trim( $value );
	}

	/**
	 * Разделитель заголовков из настроек Rank Math.
	 *
	 * @return string
	 */
	private function separator(): string {
		if ( class_exists( '\RankMath\Helper' ) ) {
			$separator = \RankMath\Helper::get_settings( 'titles.title_separator' );

			if ( is_string( $separator ) && '' !== $separator ) {
				return $separator;
			}
		}

		return '-';
	}

	/**
	 * Выводит ли SEO-плагин свои хлебные крошки (тогда свой `BreadcrumbList` не нужен).
	 *
	 * @return bool
	 */
	private function pluginHasBreadcrumbs(): bool {
		return class_exists( '\RankMath\Helper' ) && (bool) \RankMath\Helper::get_settings( 'general.breadcrumbs' );
	}

	/**
	 * Адрес — дубль страницы раздела из-за параметров фильтров.
	 *
	 * @return bool
	 */
	private function isFilteredRequest(): bool {
		if ( is_admin() ) {
			return false;
		}

		foreach ( self::FILTER_PARAMS as $param ) {
			if ( isset( $_GET[ $param ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- только проверка наличия параметра, значение не читается.
				return true;
			}
		}

		return false;
	}
}
