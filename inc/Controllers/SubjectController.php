<?php

namespace Inc\Controllers;

use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\Enums\AjaxHook;
use Inc\Repositories\SubjectRepository;
use Inc\Repositories\TaxonomyRepository;
use Inc\Repositories\MetaBoxRepository;
use Inc\Shared\Traits\TemplateRenderer;
use Inc\Shared\Traits\NumericSorter;
use Inc\Callbacks\SubjectSettingsCallbacks;
use Inc\Callbacks\TaxonomySettingsCallbacks;
use Inc\Callbacks\TemplateManagerCallbacks;
use Inc\DTO\SubjectViewDTO;
use Inc\DTO\TaxonomyDataDTO;
use Inc\Managers\PostManager;
use Inc\Registrars\SubjectCPTRegistrar;
use Inc\Registrars\SubjectTaxonomyRegistrar;

/**
 * Class SubjectController
 *
 * Контроллер для управления предметами и связанными с ними CPT.
 *
 * Отвечает за:
 * - Динамическую регистрацию CPT (задания и статьи) для каждого предмета
 * - Регистрацию таксономий (фиксированных и пользовательских)
 * - Отображение страницы управления конкретным предметом
 * - Регистрацию AJAX-хуков для CRUD операций с предметами, таксономиями и шаблонами
 *
 * @package Inc\Controllers
 * @implements ServiceInterface
 */
class SubjectController extends BaseController implements ServiceInterface {
	use TemplateRenderer;
	use NumericSorter;

	/**
	 * Конструктор.
	 *
	 * @param SubjectRepository         $subjects          Репозиторий предметов
	 * @param SubjectCPTRegistrar       $cpt_registrar      Регистратор CPT
	 * @param SubjectTaxonomyRegistrar  $tax_registrar      Регистратор таксономий
	 * @param TaxonomyRepository        $taxonomies        Репозиторий таксономий
	 * @param SubjectSettingsCallbacks  $subject_callbacks  Коллбеки для предметов
	 * @param TaxonomySettingsCallbacks $taxonomy_callbacks Коллбеки для таксономий
	 * @param TemplateManagerCallbacks  $template_callbacks Коллбеки для шаблонов
	 * @param MetaBoxRepository         $metaboxes         Репозиторий метабоксов
	 * @param PostManager               $posts             Менеджер постов
	 */
	public function __construct(
		private readonly SubjectRepository $subjects,
		private readonly SubjectCPTRegistrar $cpt_registrar,
		private readonly SubjectTaxonomyRegistrar $tax_registrar,
		private readonly TaxonomyRepository $taxonomies,
		private readonly SubjectSettingsCallbacks $subject_callbacks,
		private readonly TaxonomySettingsCallbacks $taxonomy_callbacks,
		private readonly TemplateManagerCallbacks $template_callbacks,
		private readonly MetaBoxRepository $metaboxes,
		private readonly PostManager $posts,
	) {
		parent::__construct();
	}

	// ============================ ПУБЛИЧНЫЕ МЕТОДЫ ============================ //

	/**
	 * Точка входа контроллера — регистрирует все его компоненты.
	 *
	 * Порядок важен: сначала AJAX-хуки, затем сортировка терминов,
	 * и только потом CPT/таксономии (они опираются на данные из БД).
	 *
	 * @return void
	 */
	public function register(): void {
		// Регистрация AJAX-обработчиков
		$this->registerAjaxHooks();

		// Настройка числовой сортировки терминов таксономий
		$this->setupTermSorting();

		// Регистрация CPT и таксономий для всех предметов
		$this->registerCptsAndTaxonomies();

		// Уведомление об ошибке обязательной таксономии (после серверной проверки)
		add_action( 'admin_notices', array( $this, 'showRequiredTaxNotice' ) );
	}

	public function showRequiredTaxNotice(): void {
		$key = 'fs_lms_required_tax_error_' . get_current_user_id();
		$msg = get_transient( $key );
		if ( ! $msg ) {
			return;
		}
		delete_transient( $key );
		printf(
			'<div class="notice notice-error is-dismissible"><p>Обязательная таксономия «%s» не заполнена. Задание сохранено как черновик.</p></div>',
			esc_html( $msg )
		);
	}

	/**
	 * Коллбек страницы управления конкретным предметом в админке.
	 *
	 * Вызывается WordPress при открытии /wp-admin/admin.php?page=fs_subject_{key}.
	 *
	 * @return void
	 */
	public function subjectPage(): void {
		$page = sanitize_text_field( wp_unslash( $_GET['page'] ?? '' ) );
		$key  = str_replace( 'fs_subject_', '', $page );

		$dto = $this->prepareSubjectViewData( $key );

		if ( ! $dto ) {
			echo 'Предмет не найден';

			return;
		}

		$this->render( 'subject', $dto );
	}

	// ============================ ПРИВАТНЫЕ МЕТОДЫ ============================ //

	/**
	 * Регистрирует AJAX-обработчики:
	 * - CRUD предметов (SubjectSettingsCallbacks)
	 * - CRUD таксономий (TaxonomySettingsCallbacks)
	 * - Управление шаблонами (TemplateManagerCallbacks)
	 *
	 * @return void
	 */
	private function registerAjaxHooks(): void {
		// === SubjectSettingsCallbacks -> subjects.js === //
		$subjectHooks = array(
			AjaxHook::StoreSubject,
			AjaxHook::UpdateSubject,
			AjaxHook::DeleteSubject,
			AjaxHook::ExportSubject,
			AjaxHook::ImportSubject,
			AjaxHook::GetPostsTable,
			AjaxHook::GetTasksByNumber,
		);

		// === TaxonomySettingsCallbacks -> общая логика === //
		$taxonomyHooks = array(
			AjaxHook::StoreTaxonomy,
			AjaxHook::UpdateTaxonomy,
			AjaxHook::DeleteTaxonomy,
		);

		// === TemplateManagerCallbacks -> template-manager.js === //
		$templateHooks = array(
			AjaxHook::UpdateTermTemplate,
		);

		// Регистрация хуков для предметов
		foreach ( $subjectHooks as $hook ) {
			add_action( $hook->action(), array( $this->subject_callbacks, $hook->callbackMethod() ) );
		}

		// Регистрация хуков для таксономий
		foreach ( $taxonomyHooks as $hook ) {
			add_action( $hook->action(), array( $this->taxonomy_callbacks, $hook->callbackMethod() ) );
		}

		// Регистрация хуков для шаблонов
		foreach ( $templateHooks as $hook ) {
			add_action( $hook->action(), array( $this->template_callbacks, $hook->callbackMethod() ) );
		}
	}

	/**
	 * Подключает числовую сортировку для таксономий вида "{subject}_task_number".
	 *
	 * Без неё WordPress сортирует термины лексикографически: 1, 10, 2, 3...
	 * Трейт NumericSorter исправляет порядок на числовой: 1, 2, 3, 10...
	 *
	 * @return void
	 */
	private function setupTermSorting(): void {
		$this->addNumericSort(
			'get_terms_orderby',
			't.name',
			static function ( $args ): bool {
				$tax = (array) ( $args['taxonomy'] ?? array() );

				return str_contains( reset( $tax ), '_task_number' );
			}
		);
	}

	/**
	 * Перебирает все предметы из БД и регистрирует для каждого CPT и таксономии.
	 *
	 * @return void
	 */
	private function registerCptsAndTaxonomies(): void {
		$all_subjects = $this->subjects->readAll();

		if ( empty( $all_subjects ) ) {
			return;
		}

		// Регистрация CPT и таксономий для каждого предмета
		foreach ( $all_subjects as $subject ) {
			$this->registerForSubject( $subject );
		}

		// Выполнение регистрации всех накопленных CPT и таксономий
		$this->cpt_registrar->register();
		$this->tax_registrar->register();
	}

	/**
	 * Добавляет CPT и таксономии одного предмета в очередь регистраторов.
	 *
	 * Для каждого предмета создаётся:
	 * — CPT для заданий  ({key}_tasks)     — только title
	 * — CPT для статей   ({key}_articles)  — title, editor, thumbnail
	 * — Фиксированная таксономия {key}_task_number — привязана к обоим CPT на уровне данных,
	 *   но метабокс скрыт на Tasks (выбор — через модальное окно); на Articles — dropdown.
	 * — Пользовательские таксономии — только для Tasks; на Articles не регистрируются.
	 *
	 * @param object $subject DTO предмета (содержит поля key и name)
	 *
	 * @return void
	 */
	private function registerForSubject( object $subject ): void {
		$key         = $subject->key;
		$name        = $subject->name;
		$task_cpt    = "{$key}_tasks";
		$article_cpt = "{$key}_articles";

		// Регистрация CPT для заданий (только заголовок)
		$this->cpt_registrar->addStandardType(
			$task_cpt,
			"Задания ($name)",
			'Задание',
			array( 'supports' => array( 'title' ) )
		);

		// Регистрация CPT для статей (с редактором и картинкой)
		$this->cpt_registrar->addStandardType(
			$article_cpt,
			"Статьи ($name)",
			'Статья',
			array( 'supports' => array( 'title', 'editor', 'thumbnail' ) )
		);

		// "Номер задания" регистрируется для обоих CPT — модальному окну в Tasks
		// нужен доступ к wp_set_post_terms() для этой таксономии.
		// На Articles — кастомный select-callback (WP по умолчанию рисует tag-input для
		// неиерархических таксономий, что не подходит для выбора одного значения).
		$fixed_tax_slug = "{$key}_task_number";
		$this->tax_registrar->addFixedTaxonomy(
			$fixed_tax_slug,
			array( $task_cpt, $article_cpt ),
			'Номера заданий',
			'Номер задания',
			array(
				'public'       => true,
				'show_ui'      => true,
				'meta_box_cb'  => $this->tax_registrar->buildMetaBoxCallback( 'select' ),
				'show_in_menu' => true,
				'rewrite'      => array( 'slug' => $fixed_tax_slug ),
			)
		);

		// Скрываем метабокс "Номер задания" на экране Tasks — там выбор через модальное окно.
		// Таксономия при этом остаётся зарегистрированной для Tasks на уровне данных.
		add_action(
			'add_meta_boxes',
			static function () use ( $task_cpt, $fixed_tax_slug ): void {
				remove_meta_box( "tagsdiv-{$fixed_tax_slug}", $task_cpt, 'side' );
			}
		);

		// Пользовательские таксономии — только для Tasks.
		// Не регистрируем для Articles: метабоксы там не нужны.
		foreach ( $this->taxonomies->getBySubject( $key ) as $tax_dto ) {
			$this->tax_registrar->addStandardTaxonomy(
				$tax_dto->slug,
				array( $task_cpt ),
				$tax_dto->name,
				$tax_dto->name,
				$tax_dto->display_type
			);
		}

		// Серверная проверка обязательных таксономий при публикации
		add_filter(
			'wp_insert_post_data',
			function ( array $data, array $postarr ) use ( $key ): array {
				if ( ( $data['post_type'] ?? '' ) !== "{$key}_tasks" ) {
					return $data;
				}
				if ( ! in_array( $data['post_status'], array( 'publish', 'future' ), true ) ) {
					return $data;
				}
				if ( empty( $postarr['ID'] ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
					return $data;
				}

				foreach ( $this->taxonomies->getBySubject( $key ) as $tax_dto ) {
					if ( ! $tax_dto->is_required ) {
						continue;
					}
					$values = array_filter( (array) ( $_POST['tax_input'][ $tax_dto->slug ] ?? array() ) );
					if ( empty( $values ) ) {
						$data['post_status'] = 'draft';
						set_transient( 'fs_lms_required_tax_error_' . get_current_user_id(), $tax_dto->name, 30 );
						break;
					}
				}

				return $data;
			},
			10,
			2
		);
	}

	/**
	 * Собирает все данные для страницы управления предметом и упаковывает в SubjectViewDTO.
	 *
	 * @param string $key Ключ предмета, например: "math"
	 *
	 * @return SubjectViewDTO|null DTO для шаблона или null, если предмет не найден
	 */
	private function prepareSubjectViewData( string $key ): ?SubjectViewDTO {
		$current_subject = $this->subjects->getByKey( $key );

		if ( ! $current_subject ) {
			return null;
		}

		// Фиксированная таксономия номеров — не хранится в БД пользовательских таксономий,
		// поэтому собираем вручную. Флаг is_protected запрещает удаление в интерфейсе.
		$fixed_tax_dto = new TaxonomyDataDTO(
			slug        : "{$key}_task_number",
			name        : 'Номера заданий',
			subject_key : $key,
			is_protected: true,
			is_required : true
		);

		// Получение текущей вкладки для определения необходимости построения таблиц
		$page       = sanitize_text_field( wp_unslash( $_GET['page'] ?? '' ) );
		$active_tab = sanitize_text_field( wp_unslash( $_GET['tab'] ?? '' ) );

		$tasks_table    = null;
		$articles_table = null;

		// Построение таблицы заданий только если активна соответствующая вкладка
		if ( $active_tab === 'tab-2' ) {
			$tasks_table = $this->posts->buildListTable( "{$key}_tasks", $page, 'tab-2' );
		} elseif ( $active_tab === 'tab-3' ) {
			$articles_table = $this->posts->buildListTable( "{$key}_articles", $page, 'tab-3' );
		}

		// Создаём DTO для передачи всех данных в шаблон
		return new SubjectViewDTO(
			subject_key   : $key,
			subject_data  : $current_subject,
			task_types    : $this->metaboxes->getTaskTypes( $key ),
			all_templates : apply_filters( 'fs_lms_get_templates', array() ),
			tasks_url     : admin_url( "edit.php?post_type={$key}_tasks" ),
			articles_url  : admin_url( "edit.php?post_type={$key}_articles" ),
			protected_tax : "{$key}_task_number",
			taxonomies    : array_merge( array( $fixed_tax_dto ), $this->taxonomies->getBySubject( $key ) ),
			tasks_table   : $tasks_table,
			articles_table: $articles_table,
		);
	}
}
