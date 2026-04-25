<?php

declare( strict_types=1 );

namespace Inc\Callbacks;

use Inc\Core\BaseController;
use Inc\Enums\Nonce;
use Inc\Managers\PostManager;
use Inc\Managers\TermManager;
use Inc\Repositories\BoilerplateRepository;
use Inc\Repositories\MetaBoxRepository;
use Inc\Repositories\SubjectRepository;
use Inc\Repositories\TaxonomyRepository;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;
use Inc\Shared\Traits\TaxonomySeeder;
use Inc\Shared\Traits\TemplateRenderer;

class SubjectDataCallbacks extends BaseController {
	use Authorizer;
	use Sanitizer;
	use TaxonomySeeder;
	use TemplateRenderer;

	public function __construct(
		private SubjectRepository $subjects,
		private TaxonomyRepository $taxonomies,
		private MetaBoxRepository $metaboxes,
		private BoilerplateRepository $boilerplates,
		private TermManager $terms,
		private PostManager $posts,
	) {
		parent::__construct();
	}


	// ============================ AJAX-КОЛЛБЕКИ (Работа с данными предмета) ============================ //

	/**
	 * Возвращает HTML таблицы постов для AJAX-обновления вкладки.
	 *
	 * @return void
	 */
	public function ajaxGetPostsTable(): void {
		// Проверка прав доступа и nonce
		$this->authorize( Nonce::Subject );

		// Получение и валидация параметров
		$subject_key = $this->sanitizeKey( 'subject_key' );
		$tab         = $this->sanitizeKey( 'tab' );
		$page_slug   = $this->sanitizeText( 'page_slug' );

		if ( ! in_array( $tab, array( 'tab-2', 'tab-3' ), true ) || ! $subject_key ) {
			$this->error(
				'Неверные параметры запроса.',
				array(
					'tab' => $tab,
					'key' => $subject_key,
				)
			);
		}

		// Вся низкоуровневая работа с глобальными переменными для ListTable
		// в идеале тоже должна уйти в Manager, но buildListTable уже частично это делает.
		$this->prepareTableGlobals();

		$post_type = $tab === 'tab-2' ? "{$subject_key}_tasks" : "{$subject_key}_articles";
		$t         = $this->posts->buildListTable( $post_type, $page_slug, $tab );

		ob_start();
		$t->table->search_box( $t->post_type_object->labels->search_items, 'post' );
		$search_html = ob_get_clean();

		$html = $t->views()
				. '<form id="posts-filter" method="get">' . $search_html . $t->display() . '</form>'
				. '<div id="ajax-response"></div>'
				. $t->inlineEdit();

		$t->restore();

		$this->success( array( 'html' => $html ) );
	}

	/**
	 * Получает список задач и статей по конкретному номеру задания (термину).
	 *
	 * @return void
	 */
	public function ajaxGetTasksByNumber(): void {
		$this->authorize( Nonce::Subject );

		$subject_key = $this->requireKey( 'subject_key' );
		$term_id     = absint( $_POST['term_id'] ?? 0 );
		$tax_number  = "{$subject_key}_task_number";

		if ( ! $term_id ) {
			$this->error( 'Не выбран номер задания' );
		}

		$all_taxonomies = $this->taxonomies->getBySubject( $subject_key );
		$visible_tax    = array_filter( $all_taxonomies, fn( $t ) => $t->slug !== $tax_number );

		$rows = $this->posts->getPostsByTerm( "{$subject_key}_tasks", $tax_number, $term_id, $visible_tax );

		$html = $this->view(
			'components/ajax-tables/task-ajax-table',
			array(
				'rows'       => $rows,
				'taxonomies' => $visible_tax,
			)
		);

		$this->success( array( 'html' => $html ) );
	}

	/**
	 * Получает последние 10 созданных задач по предмету (с кешированием).
	 *
	 * @return void
	 */
	public function ajaxGetRecentTasks(): void {
		$this->authorize( Nonce::Subject );
		$subject_key = $this->requireKey( 'subject_key' );
		$cache_key   = "fs_lms_recent_tasks_{$subject_key}";

		if ( $html = get_transient( $cache_key ) ) {
			$this->success( array( 'html' => $html ) );
		}

		$tax_number = "{$subject_key}_task_number";
		$taxonomies = $this->taxonomies->getBySubject( $subject_key );
		$other_tax  = array_filter( $taxonomies, fn( $t ) => $t->slug !== $tax_number );

		$rows = $this->posts->getRecentPosts( "{$subject_key}_tasks", 10, $tax_number, $other_tax );

		$html = $this->view(
			'components/ajax-tables/tasks-ajax-table',
			array(
				'rows'       => $rows,
				'taxonomies' => $other_tax,
			)
		);

		set_transient( $cache_key, $html, DAY_IN_SECONDS );

		$this->success( array( 'html' => $html ) );
	}

	/**
	 * Получает последние 10 статей по предмету (с кешированием).
	 *
	 * @return void
	 */
	public function ajaxGetRecentArticles(): void {
		$this->authorize( Nonce::Subject );
		$subject_key = $this->requireKey( 'subject_key' );
		$cache_key   = "fs_lms_recent_articles_{$subject_key}";

		if ( $html = get_transient( $cache_key ) ) {
			$this->success( array( 'html' => $html ) );
		}

		$rows = $this->posts->getRecentPosts( "{$subject_key}_articles", 10, "{$subject_key}_task_number", array() );

		$html = $this->view(
			'components/ajax-tables/articles-ajax-table',
			array(
				'rows'       => $rows,
				'taxonomies' => array(),
			)
		);

		set_transient( $cache_key, $html, DAY_IN_SECONDS );
		
		$this->success( [ 'html' => $html ] );
	}

	// ============================ ПРИВАТНЫЕ МЕТОДЫ ============================ //

	private function prepareTableGlobals(): void {
		$status = $this->sanitizeKey( 'post_status' );
		$paged  = $this->sanitizeInt( 'paged' );
		$s      = $this->sanitizeText( 's' );

		if ( $status ) {
			$_GET['post_status'] = $_REQUEST['post_status'] = $status;
		}
		if ( $paged > 1 ) {
			$_GET['paged'] = $_REQUEST['paged'] = $paged;
		}
		if ( $s !== '' ) {
			$_GET['s'] = $_REQUEST['s'] = $s;
		}
	}

	/**
	 * Обертка над render для захвата буфера
	 */
	private function view( string $path, array $args ): string {
		ob_start();
		$this->render( $path, $args );
		return (string) ob_get_clean();
	}
}
