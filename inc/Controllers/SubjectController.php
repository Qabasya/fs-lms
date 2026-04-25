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
		$task_cpt    = "{$key}_tasks";
		$article_cpt = "{$key}_articles";

		// 1. Регистрация Заданий с возможностью внешней кастомизации
		$task_args = $this->getDefaultCptArgs( 'tasks', $subject );
		$this->cpt_registrar->addStandardType(
			$task_cpt,
			'Задания',
			$task_args['labels'],
			$task_args['options']
		);

		// 2. Регистрация Статей с возможностью внешней кастомизации
		$article_args = $this->getDefaultCptArgs( 'articles', $subject );
		$this->cpt_registrar->addStandardType(
			$article_cpt,
			'Статьи',
			$article_args['labels'],
			$article_args['options']
		);

		// "Номер задания" и пользовательские таксономии (логика без изменений)
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

		add_action(
			'add_meta_boxes',
			static function () use ( $task_cpt, $fixed_tax_slug ): void {
				remove_meta_box( "tagsdiv-{$fixed_tax_slug}", $task_cpt, 'side' );
			}
		);

		foreach ( $this->taxonomies->getBySubject( $key ) as $tax_dto ) {
			$this->tax_registrar->addStandardTaxonomy( $tax_dto->slug, array( $task_cpt ), $tax_dto->name, $tax_dto->name, $tax_dto->display_type );
		}

		add_filter( 'wp_insert_post_data', array( $this->validation_callbacks, 'validateRequiredTaxonomies' ), 10, 2 );
	}

	/**
	 * Формирует дефолтную конфигурацию для CPT и прогоняет её через фильтр.
	 *
	 * @param string $type    Тип (tasks|articles)
	 * @param object $subject DTO предмета
	 * @return array{labels: array, options: array}
	 */
	private function getDefaultCptArgs( string $type, object $subject ): array {
		$args = match ( $type ) {
			'tasks' => array(
				'labels'  => array(
					'nom'    => 'Задание',
					'acc'    => 'задание',
					'gen'    => 'задания',
					'gender' => 'neuter',
				),
				'options' => array( 'supports' => array( 'title' ) ),
			),
			'articles' => array(
				'labels'  => array(
					'nom'    => 'Статья',
					'acc'    => 'статью',
					'gen'    => 'статьи',
					'gender' => 'feminine',
				),
				'options' => array( 'supports' => array( 'title', 'editor', 'thumbnail' ) ),
			),
			default => array()
		};

		/**
		 * Позволяет модифицировать аргументы регистрации CPT для конкретного предмета.
		 * * @param array  $args    Массив с labels и options.
		 *
		 * @param string $type    Тип контента (tasks или articles).
		 * @param object $subject Объект предмета.
		 */
		return apply_filters( 'fs_lms_cpt_args', $args, $type, $subject );
	}
}
