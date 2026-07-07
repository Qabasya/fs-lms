<?php

declare( strict_types=1 );

namespace Inc\Controllers\Problems;

use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\DTO\Course\ModuleDTO;
use Inc\DTO\Course\StepDTO;
use Inc\Enums\Access\Capability;
use Inc\Enums\Course\StepType;
use Inc\Enums\Wp\AjaxHook;
use Inc\Enums\Wp\Nonce;
use Inc\Enums\Wp\PostMetaName;
use Inc\Managers\Wp\PostManager;
use Inc\Services\Course\ContentUsageService;
use Inc\Services\Subject\PostTypeResolver;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
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
		private readonly SubjectRepository     $subjects,
		private readonly ContentUsageService   $usage,
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
	public function applyColumnSort( \WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		if ( PostTypeResolver::problems() !== $query->get( 'post_type' ) ) {
			return;
		}

		if ( 'template_type' === $query->get( 'orderby' ) ) {
			$query->set( 'meta_key', PostMetaName::TemplateType->value );
			$query->set( 'orderby', 'meta_value' );
		}

		if ( 'fs_lms_usage' === $query->get( 'orderby' ) ) {
			$all = $this->posts->search( PostTypeResolver::problems(), array(
				'status' => array( 'publish', 'draft', 'pending', 'private', 'future', 'fs_archived' ),
				'limit'  => -1,
			) );

			// Сортируем по тому же тексту, что показывает колонка (названия
			// курсов/тестов через ContentUsageService), а не по числу использований —
			// иначе порядок строк не совпадает с видимыми в колонке подписями.
			$labels = array();
			foreach ( $all as $post ) {
				$paths            = $this->usage->usagePathList( 'problem', $post->ID );
				$labels[ $post->ID ] = mb_strtolower( implode( ', ', array_column( $paths, 'display' ) ), 'UTF-8' );
			}

			usort( $all, static fn( $a, $b ) => $labels[ $a->ID ] <=> $labels[ $b->ID ] );

			if ( 'DESC' === strtoupper( $query->get( 'order' ) ?: 'ASC' ) ) {
				$all = array_reverse( $all );
			}

			$query->set( 'post__in', array_map( static fn( $p ) => $p->ID, $all ) );
			$query->set( 'orderby', 'post__in' );
		}

		$usage = sanitize_key( $_GET['fs_problem_usage'] ?? '' );
		if ( '' !== $usage ) {
			$work_index = $this->workProblemIndex();
			$all_used   = array();
			$by_work    = array();
			foreach ( $work_index as [ $id, , $ids ] ) {
				$by_work[ $id ] = $ids;
				foreach ( $ids as $rid ) {
					$all_used[] = $rid;
				}
			}

			if ( 'orphan' === $usage ) {
				$query->set( 'post__not_in', array_values( array_unique( $all_used ) ) );
			} elseif ( str_starts_with( $usage, 'c' ) && is_numeric( substr( $usage, 1 ) ) ) {
				$course_id   = (int) substr( $usage, 1 );
				$problem_ids = array();
				foreach ( $this->courseProblemIndex() as [ $cid, , $pids ] ) {
					if ( $cid === $course_id ) {
						$problem_ids = (array) $pids;
						break;
					}
				}
				$query->set( 'post__in', empty( $problem_ids ) ? array( 0 ) : array_values( array_unique( $problem_ids ) ) );
			} elseif ( is_numeric( $usage ) ) {
				$ids = $by_work[ (int) $usage ] ?? array();
				$query->set( 'post__in', empty( $ids ) ? array( 0 ) : array_values( array_unique( $ids ) ) );
			}
		}
	}

	public function renderProblemsFilters( string $post_type, string $which = 'top' ): void {
		if ( PostTypeResolver::problems() !== $post_type || 'top' !== $which ) {
			return;
		}

		require_once $this->plugin_path . 'templates/admin/components/UI/ui_renderers.php';

		// Фильтр по тематике (taxonomy problem_tag, обрабатывается WP нативно).
		$tags = get_terms( [ 'taxonomy' => 'problem_tag', 'hide_empty' => false ] );
		if ( ! is_wp_error( $tags ) && ! empty( $tags ) ) {
			$tag_options = [];
			foreach ( $tags as $tag ) {
				$tag_options[ $tag->slug ] = $tag->name;
			}
			render_fs_select( [
				'name'      => 'problem_tag',
				'options'   => $tag_options,
				'selected'  => sanitize_key( $_GET['problem_tag'] ?? '' ),
				'all_label' => 'Вся тематика',
			] );
		}

		// Фильтр по использованию — курсы (optgroup) + работы (optgroup).
		$selected     = sanitize_key( $_GET['fs_problem_usage'] ?? '' );
		$course_index = $this->courseProblemIndex();
		$work_index   = $this->workProblemIndex();

		echo '<select name="fs_problem_usage">';
		echo '<option value="">' . esc_html__( 'Все задачи', 'fs-lms' ) . '</option>';
		printf(
			'<option value="orphan"%s>%s</option>',
			selected( $selected, 'orphan', false ),
			esc_html__( 'Не используется', 'fs-lms' )
		);

		$course_rows = array_filter( $course_index, static fn( $row ) => ! empty( $row[2] ) );
		if ( ! empty( $course_rows ) ) {
			echo '<optgroup label="' . esc_attr__( 'По курсу', 'fs-lms' ) . '">';
			foreach ( $course_rows as [ $cid, $ctitle ] ) {
				$val = 'c' . $cid;
				printf(
					'<option value="%s"%s>%s</option>',
					esc_attr( $val ),
					selected( $selected, $val, false ),
					esc_html( $ctitle )
				);
			}
			echo '</optgroup>';
		}

		$work_rows = array_filter( $work_index, static fn( $row ) => ! empty( $row[2] ) );
		if ( ! empty( $work_rows ) ) {
			echo '<optgroup label="' . esc_attr__( 'По работе', 'fs-lms' ) . '">';
			foreach ( $work_rows as [ $wid, $wtitle ] ) {
				printf(
					'<option value="%s"%s>%s</option>',
					esc_attr( (string) $wid ),
					selected( $selected, (string) $wid, false ),
					esc_html( $wtitle )
				);
			}
			echo '</optgroup>';
		}

		echo '</select>';

		// Фильтр по автору.
		$problems   = $this->posts->search( PostTypeResolver::problems(), [
			'status' => array( 'publish', 'draft', 'pending', 'private', 'fs_archived' ),
		] );
		$author_ids = array_unique( array_map( static fn( $p ) => (int) $p->post_author, $problems ) );
		if ( count( $author_ids ) >= 2 ) {
			$author_options = [];
			foreach ( $author_ids as $uid ) {
				$user = get_user_by( 'id', $uid );
				if ( false !== $user ) {
					$author_options[ $uid ] = $user->display_name;
				}
			}
			render_fs_select( [
				'name'      => 'author',
				'options'   => $author_options,
				'selected'  => (string) (int) ( $_GET['author'] ?? 0 ),
				'all_label' => 'Все авторы',
			] );
		}
	}

	/** @var array<int, array{int, string, int[]}>|null */
	private ?array $courseProblemCache = null;

	/**
	 * Строит кросс-предметный индекс курсов → [course_id, course_title, problem_ids[]].
	 * Результат кэшируется на время запроса.
	 *
	 * @return array<int, array{int, string, int[]}>
	 */
	private function courseProblemIndex(): array {
		if ( null !== $this->courseProblemCache ) {
			return $this->courseProblemCache;
		}

		$result   = array();
		$statuses = array( 'publish', 'draft', 'pending', 'private', 'future', 'fs_archived' );

		foreach ( $this->subjects->readAll() as $subject ) {
			$courses = $this->posts->search( PostTypeResolver::courses( $subject->key ), array( 'status' => $statuses ) );
			foreach ( $courses as $course ) {
				$meta        = $this->posts->getMeta( $course->ID, PostMetaName::Meta->value );
				$meta        = is_array( $meta ) ? $meta : array();
				$modules     = ModuleDTO::fromList( is_array( $meta['modules'] ?? null ) ? $meta['modules'] : array() );
				$problem_ids = array();

				foreach ( $modules as $module ) {
					foreach ( $module->lessonIds as $lesson_id ) {
						$lesson_meta = $this->posts->getMeta( $lesson_id, PostMetaName::Meta->value );
						$lesson_meta = is_array( $lesson_meta ) ? $lesson_meta : array();
						$steps       = StepDTO::fromList( is_array( $lesson_meta['steps'] ?? null ) ? $lesson_meta['steps'] : array() );

						foreach ( $steps as $step ) {
							if ( StepType::Work !== $step->type ) {
								continue;
							}
							$work_id = (int) ( $step->payload['ref'] ?? 0 );
							if ( $work_id <= 0 ) {
								continue;
							}
							$work_meta = $this->posts->getMeta( $work_id, PostMetaName::Meta->value );
							$work_meta = is_array( $work_meta ) ? $work_meta : array();
							$ids       = array_map( 'intval', is_array( $work_meta['item_ids'] ?? null ) ? $work_meta['item_ids'] : array() );
							$problem_ids = array_merge( $problem_ids, $ids );
						}
					}
				}

				if ( ! empty( $problem_ids ) ) {
					$result[] = array( $course->ID, $course->post_title, array_unique( $problem_ids ) );
				}
			}
		}

		return $this->courseProblemCache = $result;
	}

	/**
	 * Строит кросс-предметный индекс работ → [work_id, work_title, problem_ids[]].
	 *
	 * @return array<int, array{int, string, int[]}>
	 */
	private function workProblemIndex(): array {
		$result = [];
		foreach ( $this->subjects->readAll() as $subject ) {
			$works = $this->posts->search( PostTypeResolver::works( $subject->key ), [
				'status'  => array( 'publish', 'draft', 'pending', 'private', 'future', 'fs_archived' ),
				'orderby' => 'title',
			] );
			foreach ( $works as $work ) {
				$meta     = $this->posts->getMeta( $work->ID, PostMetaName::Meta->value );
				$meta     = is_array( $meta ) ? $meta : [];
				$item_ids = array_values( array_filter( array_map( 'intval', is_array( $meta['item_ids'] ?? null ) ? $meta['item_ids'] : [] ) ) );
				$result[] = [ $work->ID, $work->post_title, $item_ids ];
			}
		}
		return $result;
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

		return $this->guard->enforce(
			$data,
			'fs_lms_problem_publish_error_',
			'Название задачи обязательно для заполнения.',
			function () {
				$postMeta   = (array) ( $_POST[ PostMetaName::Meta->value ] ?? array() );
				$templateId = $this->sanitizeKey( PostMetaName::TemplateType->value );

				return $this->validator->getSoftError( $postMeta, $templateId );
			}
		);
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
