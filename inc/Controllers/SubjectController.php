<?php

namespace Inc\Controllers;

use Inc\Callbacks\SubjectPageCallbacks;
use Inc\Callbacks\SubjectCrudCallbacks;
use Inc\Callbacks\SubjectDataCallbacks;
use Inc\Callbacks\SubjectImportExportCallbacks;
use Inc\Callbacks\SubjectValidationCallbacks;
use Inc\Callbacks\TaxonomySettingsCallbacks;
use Inc\Callbacks\TemplateManagerCallbacks;
use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\Enums\AjaxHook;
use Inc\Managers\PostManager;
use Inc\Registrars\SubjectCPTRegistrar;
use Inc\Registrars\SubjectTaxonomyRegistrar;
use Inc\Repositories\SubjectRepository;
use Inc\Repositories\TaxonomyRepository;
use Inc\Services\ContentCacheService;
use Inc\Shared\Traits\NumericSorter;

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
	use NumericSorter;
	
	/**
	 * Конструктор.
	 */
	public function __construct(
		private readonly SubjectRepository $subjects,
		private readonly SubjectCPTRegistrar $cpt_registrar,
		private readonly SubjectTaxonomyRegistrar $tax_registrar,
		private readonly TaxonomyRepository $taxonomies,
		private readonly SubjectCrudCallbacks $crud_callbacks,
		private readonly SubjectDataCallbacks $data_callbacks,
		private readonly SubjectImportExportCallbacks $import_export_callbacks,
		private readonly TaxonomySettingsCallbacks $taxonomy_callbacks,
		private readonly TemplateManagerCallbacks $template_callbacks,
		private readonly PostManager $posts,
		private readonly SubjectPageCallbacks $page_callbacks,
		private readonly SubjectValidationCallbacks $validation_callbacks,
		private readonly ContentCacheService $cache_service,
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
		// Регистрация CPT и таксономий для всех предметов
		$this->registerCptsAndTaxonomies();
		
		// Регистрация AJAX-обработчиков
		$this->registerAjaxHooks();
		
		// Настройка числовой сортировки терминов таксономий
		$this->setupTermSorting();
		
		// Уведомление об ошибке обязательной таксономии (после серверной проверки)
		add_action( 'admin_notices', array( $this->page_callbacks, 'showRequiredTaxNotice' ) );
		
		// Для кеширования
		add_action( 'save_post', array( $this->cache_service, 'clearRecentContentCache' ), 10, 2 );
		add_action( 'delete_post', array( $this->cache_service, 'clearCacheOnDelete' ) );
	}
	
	// ============================ ПРИВАТНЫЕ МЕТОДЫ ============================ //
	
	/**
	 * Регистрирует AJAX-обработчики, распределяя их по специализированным классам.
	 */
	private function registerAjaxHooks(): void {
		
		// 1. Операции создания, обновления и удаления (CRUD)
		$crudHooks = array(
			AjaxHook::StoreSubject,
			AjaxHook::UpdateSubject,
			AjaxHook::DeleteSubject,
		);
		
		// 2. Операции получения данных (UI/Tables)
		$dataHooks = array(
			AjaxHook::GetPostsTable,
			AjaxHook::GetTasksByNumber,
			AjaxHook::GetRecentTasks,
			AjaxHook::GetRecentArticles,
		);
		
		// 3. Операции импорта и экспорта
		$importExportHooks = array(
			AjaxHook::ExportSubject,
			AjaxHook::ImportSubject,
		);
		
		// 4. Таксономии
		$taxonomyHooks = array(
			AjaxHook::StoreTaxonomy,
			AjaxHook::UpdateTaxonomy,
			AjaxHook::DeleteTaxonomy,
		);
		
		// 5. Шаблоны
		$templateHooks = array(
			AjaxHook::UpdateTermTemplate,
		);
		
		// Регистрация CRUD
		foreach ( $crudHooks as $hook ) {
			add_action( $hook->action(), array( $this->crud_callbacks, $hook->callbackMethod() ) );
		}
		
		// Регистрация получения данных
		foreach ( $dataHooks as $hook ) {
			add_action( $hook->action(), array( $this->data_callbacks, $hook->callbackMethod() ) );
		}
		
		// Регистрация импорта/экспорта
		foreach ( $importExportHooks as $hook ) {
			add_action( $hook->action(), array( $this->import_export_callbacks, $hook->callbackMethod() ) );
		}
		
		// Остальные хуки
		foreach ( $taxonomyHooks as $hook ) {
			add_action( $hook->action(), array( $this->taxonomy_callbacks, $hook->callbackMethod() ) );
		}
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
			'Задания',
			array(
				'nom'    => 'Задание',
				'acc'    => 'задание',
				'gen'    => 'задания',
				'gender' => 'neuter',
			),
			array( 'supports' => array( 'title' ) )
		);
		
		// Регистрация CPT для статей (с редактором и картинкой)
		$this->cpt_registrar->addStandardType(
			$article_cpt,
			'Статьи',
			array(
				'nom'    => 'Статья',
				'acc'    => 'статью',
				'gen'    => 'статьи',
				'gender' => 'feminine',
			),
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
		
		add_filter( 'wp_insert_post_data', array( $this->validation_callbacks, 'validateRequiredTaxonomies' ), 10, 2 );
	}
	
	
	/**
	 * Сбрасывает кеш таблицы "Последние задания/статьи" при сохранении поста.
	 * Универсальный для {_tasks} и {_articles}.
	 *
	 * @param int      $post_id ID поста
	 * @param \WP_Post $post    Объект поста
	 * @param bool     $update  Флаг обновления
	 *
	 * @return void
	 */
	public function clearRecentContentCache( int $post_id, \WP_Post $post, bool $update ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		
		// Проверяем, что пост относится к нашему плагину (tasks или articles)
		if ( ! str_ends_with( $post->post_type, '_tasks' ) && ! str_ends_with( $post->post_type, '_articles' ) ) {
			return;
		}
		
		// Извлекаем subject_key (например, из "phys_tasks" или "phys_articles" получаем "phys")
		$subject_key = preg_replace( '/_(tasks|articles)$/', '', $post->post_type );
		if ( ! $subject_key ) {
			return;
		}
		
		// Ключи кеша отличаются только суффиксом
		$type_suffix = str_ends_with( $post->post_type, '_tasks' ) ? 'tasks' : 'articles';
		delete_transient( "fs_lms_recent_{$type_suffix}_{$subject_key}" );
	}
	
	/**
	 * Сбрасывает кеш при безвозвратном удалении поста.
	 *
	 * @param int $post_id ID поста
	 *
	 * @return void
	 */
	public function clearRecentContentCacheOnDelete( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}
		
		if ( ! str_ends_with( $post->post_type, '_tasks' ) && ! str_ends_with( $post->post_type, '_articles' ) ) {
			return;
		}
		
		$subject_key = preg_replace( '/_(tasks|articles)$/', '', $post->post_type );
		if ( ! $subject_key ) {
			return;
		}
		
		$type_suffix = str_ends_with( $post->post_type, '_tasks' ) ? 'tasks' : 'articles';
		delete_transient( "fs_lms_recent_{$type_suffix}_{$subject_key}" );
	}
}
