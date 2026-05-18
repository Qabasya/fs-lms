<?php

declare( strict_types=1 );

namespace Inc\Controllers;

use Inc\Callbacks\TemplateCallbacks;
use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;

/**
 * Class TaskPageController
 *
 * Контроллер frontend-страницы задания.
 *
 * Регистрирует фильтр template_include для подмены стандартного шаблона WordPress
 * на кастомный шаблон плагина при просмотре одиночной записи задания.
 *
 * @package Inc\Controllers
 */
class TaskPageController extends BaseController implements ServiceInterface {

	/**
	 * @param TemplateCallbacks $callbacks Коллбеки frontend-шаблона задания.
	 */
	public function __construct(
		private readonly TemplateCallbacks $callbacks
	) {
		parent::__construct();
	}

	/**
	 * Регистрирует фильтр подключения кастомного шаблона задания.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'template_include', array( $this->callbacks, 'loadTaskFrontendTemplate' ) );
		add_action( 'pre_get_posts',    array( $this->callbacks, 'filterTaskTaxonomyArchive' ) );
		add_filter( 'request',          array( $this->callbacks, 'filterTaskTaxonomyRequest' ) );
	}
}
