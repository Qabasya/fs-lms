<?php

declare( strict_types=1 );

namespace Inc\Controllers\Article;

use Inc\Callbacks\Article\SlugCallbacks;
use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;

/**
 * Class ArticleSlugController
 *
 * Хуки автогенерации слага статьи. Вся логика — в {@see SlugCallbacks}
 * и {@see \Inc\Services\Subject\ArticleSlugService}.
 *
 * @package Inc\Controllers\Article
 */
class ArticleSlugController extends BaseController implements ServiceInterface {

	/**
	 * @param SlugCallbacks $callbacks Коллбеки слага статьи.
	 */
	public function __construct(
		private readonly SlugCallbacks $callbacks,
	) {
		parent::__construct();
	}

	/**
	 * @return void
	 */
	public function register(): void {
		// Приоритет 20, а не 10: на 10 висит SubjectValidationCallbacks::validateRequiredTaxonomies,
		// которая при незаполненном номере задания откатывает publish в draft. Слаг обязан
		// решаться по итоговому статусу — иначе заблокированная публикация заморозила бы адрес.
		add_filter( 'wp_insert_post_data', array( $this->callbacks, 'generateSlug' ), 20, 2 );

		add_action( 'transition_post_status', array( $this->callbacks, 'lockOnPublish' ), 10, 3 );
	}
}