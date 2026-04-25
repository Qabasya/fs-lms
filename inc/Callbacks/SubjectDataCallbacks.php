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

/**
 * Class SubjectDataCallbacks
 *
 * AJAX-обработчики для получения данных предметов (таблицы, списки, кеширование).
 *
 * @package Inc\Callbacks
 *
 * ### Основные обязанности:
 *
 * 1. **Таблицы постов** — генерация HTML для WP_ListTable заданий и статей.
 * 2. **Фильтрация по номерам** — получение заданий по конкретному термину таксономии.
 * 3. **Последние записи** — получение последних 10 заданий/статей с кешированием.
 */
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
		$this->authorize( Nonce::Subject );

		// Санитизация параметров запроса
		$subject_key = $this->sanitizeKey( 'subject_key' );
		$tab         = $this->sanitizeKey( 'tab' );
		$page_slug   = $this->sanitizeText( 'page_slug' );

		// Проверка корректности вкладки (tab-2 — задания, tab-3 — статьи)
		if ( ! in_array( $tab, array( 'tab-2', 'tab-3' ), true ) || ! $subject_key ) {
			$this->error(
				'Неверные параметры запроса.',
				array(
					'tab' => $tab,
					'key' => $subject_key,
				)
			);
		}

		// Подготовка глобальных переменных для работы WP_ListTable
		$this->prepareTableGlobals();

		// Определение типа поста
		$post_type = $tab === 'tab-2' ? "{$subject_key}_tasks" : "{$subject_key}_articles";
		// buildListTable() — создаёт объект WP_ListTable для указанного типа поста
		$t = $this->posts->buildListTable( $post_type, $page_slug, $tab );

		// ob_start() — начинает буферизацию вывода
		ob_start();
		// search_box() — выводит HTML поисковой формы для таблицы
		$t->table->search_box( $t->post_type_object->labels->search_items, 'post' );
		$search_html = ob_get_clean();

		// Сборка HTML: представления (views), форма поиска, таблица, контейнер для AJAX, inline-редактор
		$html = $t->views()
				. '<form id="posts-filter" method="get">' . $search_html . $t->display() . '</form>'
				. '<div id="ajax-response"></div>'
				. $t->inlineEdit();

		// restore() — восстанавливает глобальные переменные после работы ListTable
		$t->restore();

		$this->success( array( 'html' => $html ) );
	}

	/**
	 * Получает список задач по конкретному номеру задания (термину).
	 *
	 * @return void
	 */
	public function ajaxGetTasksByNumber(): void {
		$this->authorize( Nonce::Subject );

		$subject_key = $this->requireKey( 'subject_key' );
		// absint() — преобразует значение в абсолютное целое число (без знака)
		$term_id    = absint( $_POST['term_id'] ?? 0 );
		$tax_number = "{$subject_key}_task_number";

		if ( ! $term_id ) {
			$this->error( 'Не выбран номер задания' );
		}

		// Получение всех пользовательских таксономий предмета
		$all_taxonomies = $this->taxonomies->getBySubject( $subject_key );
		// array_filter() — исключаем системную таксономию номеров заданий
		$visible_tax = array_filter( $all_taxonomies, fn( $t ) => $t->slug !== $tax_number );

		// getPostsByTerm() — получает посты, привязанные к указанному термину таксономии
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

		// get_transient() — получает временные данные из кеша (хранятся в options таблице)
		if ( $html = get_transient( $cache_key ) ) {
			$this->success( array( 'html' => $html ) );
		}

		$tax_number = "{$subject_key}_task_number";
		$taxonomies = $this->taxonomies->getBySubject( $subject_key );
		$other_tax  = array_filter( $taxonomies, fn( $t ) => $t->slug !== $tax_number );

		// getRecentPosts() — получает последние N постов указанного типа
		$rows = $this->posts->getRecentPosts( "{$subject_key}_tasks", 10, $tax_number, $other_tax );

		$html = $this->view(
			'components/ajax-tables/tasks-ajax-table',
			array(
				'rows'       => $rows,
				'taxonomies' => $other_tax,
			)
		);

		// set_transient() — сохраняет данные в кеш на указанный срок
		// DAY_IN_SECONDS — константа WordPress (86400 секунд = 1 сутки)
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

		$this->success( array( 'html' => $html ) );
	}

	// ============================ ПРИВАТНЫЕ МЕТОДЫ ============================ //

	/**
	 * Подготавливает глобальные переменные $_GET/$_REQUEST для WP_ListTable.
	 *
	 * @return void
	 */
	private function prepareTableGlobals(): void {
		$status = $this->sanitizeKey( 'post_status' );
		$paged  = $this->sanitizeInt( 'paged' );
		$s      = $this->sanitizeText( 's' );

		// WP_ListTable читает параметры фильтрации из суперглобальных массивов
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
	 * Обертка над render() для захвата буфера вывода.
	 *
	 * @param string $path Путь к шаблону (относительно папки templates)
	 * @param array  $args Аргументы, передаваемые в шаблон
	 *
	 * @return string
	 */
	private function view( string $path, array $args ): string {
		ob_start();
		$this->render( $path, $args );
		return (string) ob_get_clean();
	}
}
