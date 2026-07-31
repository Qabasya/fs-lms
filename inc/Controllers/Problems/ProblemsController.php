<?php

declare( strict_types=1 );

namespace Inc\Controllers\Problems;

use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\Enums\Access\Capability;
use Inc\Enums\Wp\AjaxHook;
use Inc\Enums\Wp\Nonce;
use Inc\Enums\Wp\PostMetaName;
use Inc\Controllers\Builders\ProblemListFilters;
use Inc\Managers\Wp\PostManager;
use Inc\Registrars\ProblemBankRegistrar;
use Inc\Services\Subject\PostTypeResolver;
use Inc\Services\Task\TaskPublishGuard;
use Inc\Services\Task\TaskPublishValidator;
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
		private readonly TemplateRegistry      $registry,
		private readonly PostManager           $posts,
		private readonly TaskPublishValidator  $validator,
		private readonly TaskPublishGuard      $guard,
		private readonly ProblemBankRegistrar  $bank,
		private readonly ProblemListFilters    $filters,
	) {
		parent::__construct();
	}

	public function register(): void {
		$cpt = PostTypeResolver::problems();

		add_action( 'init', array( $this->bank, 'registerCpt' ) );
		add_action( 'init', array( $this->bank, 'registerTaxonomy' ) );
		add_action( 'add_meta_boxes', array( $this, 'addTemplateMetabox' ) );
		add_action( 'add_meta_boxes_' . $cpt, array( $this, 'moveAuthorMetaboxToSide' ), 20 );
		add_action( 'save_post_' . $cpt, array( $this, 'saveTemplateType' ) );
		add_action( AjaxHook::SetTaskTemplateType->action(), array( $this, 'ajaxSetTemplateType' ) );

		add_filter( "manage_{$cpt}_posts_columns", array( $this, 'addColumns' ) );
		add_action( "manage_{$cpt}_posts_custom_column", array( $this, 'renderColumn' ), 10, 2 );
		add_filter( "manage_edit-{$cpt}_sortable_columns", array( $this, 'sortableColumns' ) );
		add_action( 'pre_get_posts', array( $this, 'applyColumnSort' ) );
		add_action( 'restrict_manage_posts', array( $this, 'renderProblemsFilters' ), 10, 2 );
		add_action( 'admin_notices', array( $this, 'renderBankDescription' ) );
		add_filter( 'wp_insert_post_data', array( $this, 'validateBeforePublish' ), 10, 2 );
		add_action( 'admin_notices', array( $this, 'showPublishError' ) );
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
		$this->render( 'admin/metaboxes/template-select', array(
			'name'      => PostMetaName::TemplateType->value,
			'current'   => $current,
			'templates' => $this->registry->getAll(),
		) );
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
		$this->authorize( Nonce::SaveMeta, Capability::AuthorLmsCourses );

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
		$order = array( 'cb', 'title' );

		// Таксономии (добавляются WP автоматически через show_admin_column).
		foreach ( array_keys( $columns ) as $key ) {
			if ( str_starts_with( $key, 'taxonomy-' ) ) {
				$order[] = $key;
			}
		}

		$order = array_merge( $order, array( 'template_type', 'author', 'fs_lms_usage', 'date' ) );

		$result = array();
		foreach ( $order as $key ) {
			if ( 'template_type' === $key ) {
				$result['template_type'] = 'Тип шаблона';
			} elseif ( isset( $columns[ $key ] ) ) {
				$result[ $key ] = $columns[ $key ];
			}
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
		$columns['template_type']        = 'template_type';
		$columns['taxonomy-problem_tag'] = 'taxonomy-problem_tag';
		$columns['fs_lms_usage']         = 'fs_lms_usage';

		return $columns;
	}

	/**
	 * Применяет сортировку и фильтры списка задач.
	 */
	/**
	 * Сортировка и фильтры списка задач (хук pre_get_posts).
	 *
	 * @param \WP_Query $query Запрос экрана
	 *
	 * @return void
	 */
	public function applyColumnSort( \WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		if ( PostTypeResolver::problems() !== $query->get( 'post_type' ) ) {
			return;
		}

		// Сортировка по типу шаблона — обычная мета-сортировка, остальное — в фильтрах банка.
		if ( 'template_type' === $query->get( 'orderby' ) ) {
			$query->set( 'meta_key', PostMetaName::TemplateType->value );
			$query->set( 'orderby', 'meta_value' );
		}

		$this->filters->apply( $query );
	}

	/**
	 * Фильтры над таблицей банка задач (хук restrict_manage_posts).
	 *
	 * @param string $post_type CPT экрана
	 * @param string $which     Позиция панели: top|bottom
	 *
	 * @return void
	 */
	public function renderProblemsFilters( string $post_type, string $which = 'top' ): void {
		if ( PostTypeResolver::problems() !== $post_type || 'top' !== $which ) {
			return;
		}

		$this->render( 'admin/problems/problem-filters', $this->filters->data() );
	}

	/**
	 * Хук wp_insert_post_data: блокирует публикацию задачи из банка,
	 * если не заполнены название, условие или ответ.
	 *
	 * @param array $data    Очищенные данные поста
	 * @param array $postarr Неочищенные данные из $_POST
	 *
	 * @return array
	 */
	public function validateBeforePublish( array $data, array $postarr ): array {
		if ( PostTypeResolver::problems() !== ( $data['post_type'] ?? '' ) ) {
			return $data;
		}

		$postId = (int) ( $postarr['ID'] ?? 0 );

		return $this->guard->enforce(
			$data,
			'fs_lms_problem_publish_error_',
			'Название задачи обязательно для заполнения.',
			function () use ( $postId ) {
				$hasMetaForm = isset( $_POST[ PostMetaName::Meta->value ] );

				// Программная вставка (импорт пакета, рестор банка): формы нет, поста
				// ещё нет — мета запишется сразу после insert, валидировать нечего.
				if ( $postId <= 0 && ! $hasMetaForm ) {
					return null;
				}

				// Быстрое/массовое редактирование и программный wp_update_post форму
				// метабокса не шлют — берём сохранённое состояние, иначе валидатор
				// видел пустую мету, сваливался на «Стандартный» шаблон и откатывал
				// опубликованную задачу в черновик.
				$postMeta = $hasMetaForm
					? $this->unslashArray( PostMetaName::Meta->value )
					: $this->storedMeta( $postId );

				$templateId = $this->sanitizeKey( PostMetaName::TemplateType->value );
				if ( '' === $templateId && $postId > 0 ) {
					$stored     = $this->posts->getMeta( $postId, PostMetaName::TemplateType->value );
					$templateId = is_string( $stored ) ? $stored : '';
				}

				return $this->validator->getSoftError( $postMeta, $templateId );
			}
		);
	}

	/**
	 * Сохранённая мета задачи (пустой массив, если меты ещё нет).
	 *
	 * @param int $postId ID задачи банка.
	 *
	 * @return array<string, mixed>
	 */
	private function storedMeta( int $postId ): array {
		$meta = $postId > 0 ? $this->posts->getMeta( $postId, PostMetaName::Meta->value ) : null;

		return is_array( $meta ) ? $meta : array();
	}

	/**
	 * Хук admin_notices: показывает ошибку валидации после неудачной публикации.
	 */
	public function showPublishError(): void {
		$screen = get_current_screen();
		if ( ! $screen || PostTypeResolver::problems() !== $screen->post_type ) {
			return;
		}

		$this->guard->renderDeferredError( 'fs_lms_problem_publish_error_', __( 'Невозможно опубликовать задачу', 'fs-lms' ) );
	}
}
