<?php

declare( strict_types=1 );

namespace Inc\Controllers\Builders;

/**
 * Class PostsListTablePresenter
 *
 * Показывает нативную таблицу записей WP (`WP_Posts_List_Table`) на кастомной
 * админ-странице плагина: готовит таблицу, подменяет ссылки `edit.php` на
 * `admin.php?page=…&tab=…` и возвращает готовый HTML.
 *
 * @package Inc\Controllers\Builders
 *
 * ### Почему это презентер, а не DTO
 *
 * Класс держит живой объект таблицы, рендерит HTML через буфер вывода и
 * подменяет `$_SERVER['REQUEST_URI']` (иначе у таблицы ломаются пагинация и
 * фильтры). После вывода вызывающий код обязан вызвать {@see restore()}.
 */
class PostsListTablePresenter {

	/**
	 * @param \WP_Posts_List_Table $table          Объект таблицы записей
	 * @param \WP_Post_Type|null   $postTypeObject Объект типа записи (метаданные CPT)
	 * @param string               $postType       Слаг типа записи (например, 'math_tasks')
	 * @param string               $editBase       Базовый URL редактирования ('edit.php?post_type=…')
	 * @param string               $customBase     URL страницы плагина для подмены ссылок
	 * @param string               $originalUri    Оригинальный REQUEST_URI для восстановления
	 * @param string               $tab            Идентификатор вкладки (tab-2, tab-3)
	 * @param string               $pageSlug       Слаг страницы (например, 'fs_subject_math')
	 */
	private function __construct(
		public readonly \WP_Posts_List_Table $table,
		public readonly ?\WP_Post_Type $postTypeObject,
		public readonly string $postType,
		private readonly string $editBase,
		private readonly string $customBase,
		private readonly string $originalUri,
		public readonly string $tab = '',
		public readonly string $pageSlug = '',
	) {}

	/**
	 * Готовит таблицу записей для страницы плагина.
	 *
	 * Побочный эффект: подменяет текущий экран и REQUEST_URI — вызывающий код
	 * обязан вызвать {@see restore()} после вывода.
	 *
	 * @param string $postType Слаг CPT (например, 'math_tasks')
	 * @param string $page     Значение GET-параметра page
	 * @param string $tab      Слаг вкладки (например, 'tab-2')
	 *
	 * @return self
	 */
	public static function for( string $postType, string $page, string $tab ): self {
		// Подключаем класс WP_Posts_List_Table, если не загружен
		if ( ! class_exists( 'WP_Posts_List_Table' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-posts-list-table.php';
		}

		// set_current_screen() — устанавливает текущий экран для корректной работы ListTable
		set_current_screen( 'edit-' . $postType );

		// _get_list_table() — возвращает экземпляр класса таблицы
		$table = _get_list_table( 'WP_Posts_List_Table', array( 'screen' => 'edit-' . $postType ) );

		// Подмена REQUEST_URI для правильной работы пагинации и фильтров
		$uriArgs = array(
			'page' => $page,
			'tab'  => $tab,
		);
		if ( ! empty( $_GET['post_status'] ) ) {
			$uriArgs['post_status'] = sanitize_key( $_GET['post_status'] );
		}

		$originalUri            = (string) $_SERVER['REQUEST_URI'];
		$_SERVER['REQUEST_URI'] = '/wp-admin/admin.php?' . http_build_query( $uriArgs );

		$_GET['post_type'] = $postType;
		$table->prepare_items();

		return new self(
			table         : $table,
			postTypeObject: get_post_type_object( $postType ),
			postType      : $postType,
			editBase      : admin_url( 'edit.php?post_type=' . $postType ),
			customBase    : admin_url( 'admin.php?page=' . $page . '&tab=' . $tab ),
			originalUri   : $originalUri,
			tab           : $tab,
			pageSlug      : $page,
		);
	}

	/**
	 * HTML представлений таблицы (ссылки-фильтры «Все | Опубликованные | Черновики | Корзина»).
	 */
	public function views(): string {
		return $this->render( fn() => $this->table->views() );
	}

	/**
	 * HTML самой таблицы: заголовки колонок, строки и пагинация.
	 */
	public function display(): string {
		return $this->render( fn() => $this->table->display() );
	}

	/**
	 * HTML формы быстрого редактирования (inline edit).
	 */
	public function inlineEdit(): string {
		ob_start();
		$this->table->inline_edit();

		return (string) ob_get_clean();
	}

	/**
	 * HTML формы поиска по записям.
	 */
	public function searchBox(): string {
		ob_start();
		$this->table->search_box( $this->postTypeObject?->labels->search_items ?? '', 'post' );

		return (string) ob_get_clean();
	}

	/**
	 * Восстанавливает оригинальный REQUEST_URI после работы с таблицей.
	 *
	 * @return void
	 */
	public function restore(): void {
		$_SERVER['REQUEST_URI'] = $this->originalUri;
	}

	/**
	 * Снимает вывод части таблицы и подменяет в нём ссылки edit.php на страницу плагина.
	 *
	 * @param callable $output Вызов метода таблицы, печатающего HTML
	 */
	private function render( callable $output ): string {
		ob_start();
		$output();

		return str_replace( $this->editBase, $this->customBase, (string) ob_get_clean() );
	}
}
