<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Article;

use Inc\Controllers\Builders\ArticleDataBuilder;
use Inc\Core\BaseController;
use Inc\Services\Subject\PostTypeResolver;

/**
 * Class TemplateCallbacks
 *
 * Коллбеки frontend-шаблона статьи.
 *
 * Обрабатывает фильтр template_include: на singular-странице CPT
 * {subject}_articles подменяет шаблон темы на single-article.php и кладёт
 * данные страницы в query var.
 *
 * @package Inc\Callbacks\Article
 */
class TemplateCallbacks extends BaseController {

	/**
	 * @param ArticleDataBuilder $article_data_builder Строитель данных страницы статьи.
	 */
	public function __construct(
		private readonly ArticleDataBuilder $article_data_builder,
	) {
		parent::__construct();
	}

	/**
	 * Подменяет путь к шаблону для одиночной страницы статьи.
	 *
	 * Записи нет (удалена или это не статья) — отдаём 404 темы, а не пустую
	 * страницу: то же поведение, что у страницы задания.
	 *
	 * @param string $template Путь к текущему шаблону темы.
	 *
	 * @return string Путь к шаблону плагина или оригинальный путь.
	 */
	public function loadArticleTemplate( string $template ): string {
		if ( ! is_singular() ) {
			return $template;
		}

		$post_type = (string) get_post_type();

		if ( ! PostTypeResolver::isArticlePostType( $post_type ) ) {
			return $template;
		}

		$custom_template = FS_LMS_PATH . 'templates/frontend/single-article.php';

		if ( ! file_exists( $custom_template ) ) {
			return $template;
		}

		$article_data = $this->article_data_builder->getArticleData( get_queried_object_id() );

		if ( ! $article_data->post ) {
			return $this->notFound( $template );
		}

		set_query_var( 'fs_article_data', $article_data );

		return $custom_template;
	}

	/**
	 * Переводит текущий запрос в 404 и возвращает шаблон «не найдено».
	 *
	 * @param string $template Путь к текущему шаблону темы (фолбэк).
	 *
	 * @return string
	 */
	private function notFound( string $template ): string {
		global $wp_query;

		if ( $wp_query instanceof \WP_Query ) {
			$wp_query->set_404();
		}

		status_header( 404 );
		nocache_headers();

		$not_found = get_404_template();

		return '' !== $not_found ? $not_found : $template;
	}
}