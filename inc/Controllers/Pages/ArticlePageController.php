<?php

declare( strict_types=1 );

namespace Inc\Controllers\Pages;

use Inc\Callbacks\Article\TemplateCallbacks;
use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;

/**
 * Class ArticlePageController
 *
 * Контроллер публичной страницы статьи (CPT {subject}_articles).
 *
 * Регистрирует:
 *   - template_include — подмена шаблона темы на singular-странице статьи.
 *
 * @package Inc\Controllers\Pages
 */
class ArticlePageController extends BaseController implements ServiceInterface {

	public function __construct(
		private readonly TemplateCallbacks $callbacks,
	) {
		parent::__construct();
	}

	public function register(): void {
		// 'template_include' — фильтр для подмены шаблона темы
		add_filter( 'template_include', array( $this->callbacks, 'loadArticleTemplate' ) );
	}
}