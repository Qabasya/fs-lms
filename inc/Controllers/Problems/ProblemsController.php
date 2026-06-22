<?php

declare( strict_types=1 );

namespace Inc\Controllers\Problems;

use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\Enums\Access\Capability;
use Inc\Enums\Wp\AjaxHook;
use Inc\Enums\Wp\Nonce;
use Inc\Enums\Wp\PostMetaName;
use Inc\Managers\Wp\PostManager;
use Inc\Services\Subject\PostTypeResolver;
use Inc\Services\Template\TemplateRegistry;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;
use Inc\Shared\Traits\TemplateRenderer;

/**
 * Class ProblemsController
 *
 * Регистрирует глобальный CPT `fs_lms_problems` и таксономию `problem_tag`.
 * Добавляет метабокс выбора шаблона редактора (те же шаблоны, что у заданий).
 *
 * @package Inc\Controllers
 */
class ProblemsController extends BaseController implements ServiceInterface {

	use Authorizer;
	use Sanitizer;
	use TemplateRenderer;

	public function __construct(
		private readonly TemplateRegistry $registry,
		private readonly PostManager      $posts,
	) {
		parent::__construct();
	}

	public function register(): void {
		$cpt = PostTypeResolver::problems();

		add_action( 'init', array( $this, 'registerCpt' ) );
		add_action( 'init', array( $this, 'registerTaxonomy' ) );
		add_action( 'add_meta_boxes', array( $this, 'addTemplateMetabox' ) );
		add_action( 'add_meta_boxes_' . $cpt, array( $this, 'moveAuthorMetaboxToSide' ), 20 );
		add_action( 'save_post_' . $cpt, array( $this, 'saveTemplateType' ) );
		add_action( AjaxHook::SetTaskTemplateType->action(), array( $this, 'ajaxSetTemplateType' ) );

		add_filter( "manage_{$cpt}_posts_columns", array( $this, 'addColumns' ) );
		add_action( "manage_{$cpt}_posts_custom_column", array( $this, 'renderColumn' ), 10, 2 );
		add_filter( "manage_edit-{$cpt}_sortable_columns", array( $this, 'sortableColumns' ) );
		add_action( 'pre_get_posts', array( $this, 'applyColumnSort' ) );
		add_action( 'admin_notices', array( $this, 'renderBankDescription' ) );
	}

	/**
	 * Выводит описание над таблицей на экране списка задач.
	 *
	 * Хук admin_notices срабатывает на всех экранах — ограничиваем выводом
	 * только на нативном списке `edit.php?post_type=fs_lms_problems`.
	 */
	public function renderBankDescription(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'edit-' . PostTypeResolver::problems() !== $screen->id ) {
			return;
		}

		$this->render( 'admin/components/problems-bank-notice' );
	}

	public function registerCpt(): void {
		register_post_type( PostTypeResolver::problems(), array(
			'labels'              => array(
				'name'          => 'Задачи',
				'singular_name' => 'Задача',
				'add_new_item'  => 'Добавить задачу',
				'edit_item'     => 'Редактировать задачу',
				'search_items'  => 'Найти задачу',
				'not_found'     => 'Задачи не найдены',
			),
			'public'              => false,
			'show_ui'             => true,
			'show_in_menu'        => false,
			'show_in_rest'        => false,
			'exclude_from_search' => true,
			'capability_type'     => 'fs_lms_content',
			'map_meta_cap'        => true,
			'supports'            => array( 'title', 'author' ),
			'rewrite'             => false,
		) );
	}

	public function registerTaxonomy(): void {
		register_taxonomy( 'problem_tag', array( PostTypeResolver::problems() ), array(
			'labels'            => array(
				'name'          => 'Тематика',
				'singular_name' => 'Тема',
				'add_new_item'  => 'Добавить тему',
				'all_items'     => 'Все темы',
			),
			'hierarchical'      => false,
			'show_ui'           => true,
			'show_in_rest'      => false,
			'show_admin_column' => true,
			'rewrite'           => false,
		) );
	}

	public function addTemplateMetabox(): void {
		add_meta_box(
			'fs_lms_problem_template',
			'Тип шаблона',
			array( $this, 'renderTemplateMetabox' ),
			PostTypeResolver::problems(),
			'side',
		);
	}

	public function renderTemplateMetabox( \WP_Post $post ): void {
		$current = (string) $this->posts->getMeta( $post->ID, PostMetaName::TemplateType->value );
		wp_nonce_field( Nonce::SaveMeta->value, 'fs_lms_meta_nonce' );
		echo '<select name="' . esc_attr( PostMetaName::TemplateType->value ) . '" class="fs-lms-template-select">';
		foreach ( $this->registry->getAll() as $template ) {
			$selected = selected( $current, $template->get_id(), false );
			echo '<option value="' . esc_attr( $template->get_id() ) . '"' . $selected . '>'
				. esc_html( $template->get_name() ) . '</option>';
		}
		echo '</select>';
	}

	public function saveTemplateType( int $post_id ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! $this->authorizePostSave( Nonce::SaveMeta, $post_id ) ) {
			return;
		}
		$template_id = $this->sanitizeKey( PostMetaName::TemplateType->value );
		if ( '' !== $template_id ) {
			$this->posts->updateMeta( $post_id, PostMetaName::TemplateType->value, $template_id );
		}
	}

	/**
	 * Переносит метабокс «Автор» в правый сайдбар (контекст `side`).
	 *
	 * Нельзя пере-добавлять под тем же id `authordiv`: `remove_meta_box` ставит
	 * маркер `false`, и `add_meta_box` с тем же id наследует исходный контекст/приоритет
	 * (бокс пропадает). Поэтому снимаем core-`authordiv` и регистрируем СВОЙ бокс с
	 * другим id, переиспользуя нативный рендер `post_author_meta_box` (поле
	 * `post_author_override` ядро сохраняет само).
	 */
	public function moveAuthorMetaboxToSide(): void {
		$cpt = PostTypeResolver::problems();
		remove_meta_box( 'authordiv', $cpt, 'normal' );
		add_meta_box( 'fs_lms_problem_author', 'Автор', 'post_author_meta_box', $cpt, 'side' );
	}

	/**
	 * AJAX: авто-сохранение типа шаблона при смене в селекторе.
	 * JS после успеха перезагружает экран редактирования — метабокс полей
	 * перерисовывается под новый тип (`MetaBoxController` через `TemplateResolver`).
	 */
	public function ajaxSetTemplateType(): void {
		$this->authorize( Nonce::SaveMeta, Capability::ManageLMSAssignments );

		$post_id     = $this->requireInt( 'post_id' );
		$template_id = $this->sanitizeKey( 'template_type' );

		if ( '' === $template_id || null === $this->registry->get( $template_id ) ) {
			$this->error( 'Неизвестный тип шаблона.' );
		}
		if ( ! get_post( $post_id ) ) {
			$this->error( 'Пост не найден.' );
		}

		$this->posts->updateMeta( $post_id, PostMetaName::TemplateType->value, $template_id );
		$this->success();
	}

	/**
	 * Добавляет колонку «Тип шаблона» перед колонкой даты.
	 *
	 * Колонки «Тематика» (таксономия `problem_tag`) и «Автор» добавляются
	 * ядром WP автоматически (`show_admin_column` и `supports => author`).
	 *
	 * @param array<string, string> $columns
	 *
	 * @return array<string, string>
	 */
	public function addColumns( array $columns ): array {
		$result = array();
		foreach ( $columns as $key => $label ) {
			if ( 'date' === $key ) {
				$result['template_type'] = 'Тип шаблона';
			}
			$result[ $key ] = $label;
		}

		return $result;
	}

	/**
	 * Отрисовывает значение кастомной колонки «Тип шаблона».
	 */
	public function renderColumn( string $column, int $post_id ): void {
		if ( 'template_type' !== $column ) {
			return;
		}

		$template_id = (string) $this->posts->getMeta( $post_id, PostMetaName::TemplateType->value );
		$template    = '' !== $template_id ? $this->registry->get( $template_id ) : null;

		echo esc_html( null !== $template ? $template->get_name() : '—' );
	}

	/**
	 * Делает колонку «Тип шаблона» сортируемой.
	 *
	 * @param array<string, string> $columns
	 *
	 * @return array<string, string>
	 */
	public function sortableColumns( array $columns ): array {
		$columns['template_type'] = 'template_type';

		return $columns;
	}

	/**
	 * Применяет сортировку списка задач по типу шаблона (мета-значение).
	 */
	public function applyColumnSort( \WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		if ( PostTypeResolver::problems() !== $query->get( 'post_type' ) ) {
			return;
		}
		if ( 'template_type' !== $query->get( 'orderby' ) ) {
			return;
		}

		$query->set( 'meta_key', PostMetaName::TemplateType->value );
		$query->set( 'orderby', 'meta_value' );
	}
}
