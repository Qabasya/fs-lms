<?php

namespace Inc\Controllers;

use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\Registrars\PluginRegistrar;
use Inc\Repositories\SubjectRepository;
use Inc\Repositories\TaxonomyRepository;
use Inc\Shared\Traits\TemplateRenderer;
use Inc\Shared\Traits\NumericSorter;
use Inc\Callbacks\SubjectSettingsCallbacks;
use Inc\Callbacks\TaxonomySettingsCallbacks;

/**
 * Class SubjectController
 *
 * Контроллер для управления предметами и связанными с ними CPT.
 *
 * Отвечает за:
 * - Динамическую регистрацию CPT (задания и статьи) для каждого предмета
 * - Регистрацию таксономий (фиксированных и пользовательских)
 * - Отображение страницы управления конкретным предметом
 * - Регистрацию AJAX-хуков для CRUD операций с предметами и таксономиями
 *
 * @package Inc\Controllers
 * @implements ServiceInterface
 *
 * @method void render( string $template, array $data = [] ) — трейт TemplateRenderer
 */
class SubjectController extends BaseController implements ServiceInterface {
	use TemplateRenderer;
	use NumericSorter;

	/**
	 * Репозиторий для работы с предметами.
	 *
	 * @var SubjectRepository
	 */
	private SubjectRepository $subjects;

	/**
	 * Репозиторий для работы с кастомными таксономиями.
	 *
	 * @var TaxonomyRepository
	 */
	private TaxonomyRepository $taxonomies;

	/**
	 * Композитный регистратор плагина.
	 *
	 * @var PluginRegistrar
	 */
	private PluginRegistrar $registrar;

	private SubjectSettingsCallbacks $subjectCallbacks;
	private TaxonomySettingsCallbacks $taxonomyCallbacks;

	/**
	 * Конструктор.
	 *
	 * @param SubjectRepository $subjects Репозиторий предметов
	 * @param PluginRegistrar $registrar Композитный регистратор плагина
	 * @param TaxonomyRepository $taxonomies Репозиторий кастомных таксономий
	 */
	public function __construct(
		SubjectRepository $subjects,
		PluginRegistrar $registrar,
		TaxonomyRepository $taxonomies,
		SubjectSettingsCallbacks $subjectCallbacks,
		TaxonomySettingsCallbacks $taxonomyCallbacks
	) {
		parent::__construct();
		$this->subjects          = $subjects;
		$this->registrar         = $registrar;
		$this->taxonomies        = $taxonomies;
		$this->subjectCallbacks  = $subjectCallbacks;
		$this->taxonomyCallbacks = $taxonomyCallbacks;
	}

	/**
	 * Регистрирует все компоненты контроллера.
	 *
	 * Вызывается один раз при инициализации плагина (в Init.php).
	 *
	 * Процесс регистрации:
	 * 1. Настройка сортировки терминов таксономий по числовому значению
	 * 2. Для каждого предмета:
	 *    - Регистрация CPT для заданий и статей
	 *    - Регистрация фиксированной таксономии "Номера заданий"
	 *    - Регистрация пользовательских таксономий из репозитория
	 * 3. Выполнение регистрации через регистраторы
	 *
	 * @return void
	 */
	public function register(): void {
		// ====================== РЕГИСТРАЦИЯ AJAX-ОБРАБОТЧИКОВ ======================
		// Обработчики для предметов (SubjectSettingsCallbacks)
		add_action( 'wp_ajax_fs_store_subject', [ $this->subjectCallbacks, 'storeSubject' ] );
		add_action( 'wp_ajax_fs_update_subject', [ $this->subjectCallbacks, 'updateSubject' ] );
		add_action( 'wp_ajax_fs_delete_subject', [ $this->subjectCallbacks, 'deleteSubject' ] );
		add_action( 'wp_ajax_fs_update_term_template', [ $this->subjectCallbacks, 'updateTaskTemplate' ] );

		// Обработчики для таксономий (TaxonomySettingsCallbacks)
		add_action( 'wp_ajax_fs_store_taxonomy', [ $this->taxonomyCallbacks, 'storeTaxonomy' ] );
		add_action( 'wp_ajax_fs_update_taxonomy', [ $this->taxonomyCallbacks, 'updateTaxonomy' ] );
		add_action( 'wp_ajax_fs_delete_taxonomy', [ $this->taxonomyCallbacks, 'deleteTaxonomy' ] );

		// ====================== НАСТРОЙКА СОРТИРОВКИ ======================
		// Настройка сортировки терминов таксономий по числовому значению
		// Применяется только к таксономиям, содержащим "_task_number"
		$this->addNumericSort(
			'get_terms_orderby',
			't.name',
			function ( $args ) {
				$tax = (array) ( $args['taxonomy'] ?? [] );

				return str_contains( reset( $tax ), '_task_number' );
			}
		);

		// ====================== РЕГИСТРАЦИЯ CPT И ТАКСОНОМИЙ ======================
		$all_subjects = $this->subjects->read_all();

		if ( empty( $all_subjects ) ) {
			return;
		}

		// Регистрируем CPT и таксономии для каждого предмета
		foreach ( $all_subjects as $key => $data ) {
			$name        = $data['name'];
			$task_cpt    = "{$key}_tasks";
			$article_cpt = "{$key}_articles";

			// 1. Регистрация CPT для заданий (только заголовок, без контента)
			$this->registrar->cpt()->addStandardType(
				$task_cpt,
				"Задания ($name)",
				"Задание",
				[
					'supports' => [ 'title' ]
				]
			);

			// 2. Регистрация CPT для статей (с редактором и картинкой)
			$this->registrar->cpt()->addStandardType(
				$article_cpt,
				"Статьи ($name)",
				"Статья",
				[
					'supports' => [ 'title', 'editor', 'thumbnail' ]
				]
			);

			// 3. Регистрация фиксированной таксономии "Номера заданий"
			$fixed_tax_slug = "{$key}_task_number";

			$this->registrar->taxonomy()
			                ->addFixedTaxonomy(
				                $fixed_tax_slug,
				                [ $task_cpt ],
				                "Номера заданий ($name)",
				                "Номер задания",
				                [
					                'public'            => true,
					                'show_ui'           => true,
					                'show_in_menu'      => true,
					                'show_admin_column' => true,
					                'hierarchical'      => false,
					                'query_var'         => true,
					                'rewrite'           => [ 'slug' => $fixed_tax_slug ],
					                'capabilities'      => [
						                'manage_terms' => 'manage_categories',  // Управление терминами
						                'edit_terms'   => 'manage_categories',  // Редактирование терминов
						                'delete_terms' => 'manage_categories',  // Удаление терминов
						                'assign_terms' => 'edit_posts',         // Присвоение терминов постам
					                ],
				                ]
			                );

			// 4. Регистрация пользовательских таксономий из репозитория
			$custom_taxes = $this->taxonomies->get_by_subject( $key );
			foreach ( $custom_taxes as $tax_slug => $tax_data ) {
				$this->registrar->taxonomy()
				                ->addStandardTaxonomy(
					                $tax_slug,
					                [ $task_cpt, $article_cpt ],      // Привязываем и к заданиям, и к статьям
					                $tax_data['name'],
					                $tax_data['name']
				                );
			}
		}

		// Выполняем регистрацию всех накопленных CPT и таксономий
		$this->registrar->cpt()->register();
		$this->registrar->taxonomy()->register();
	}


	/**
	 * Коллбек для страницы управления конкретным предметом.
	 *
	 * Извлекает ключ предмета из URL-параметра page,
	 * отображает информацию о предмете и ссылки на связанные CPT.
	 *
	 * Формат URL: /wp-admin/admin.php?page=fs_subject_{key}
	 *
	 * @return void
	 */
	public function subjectPage(): void {
		// Извлекаем ключ предмета из URL
		$page = $_GET['page'] ?? '';
		$key  = str_replace( 'fs_subject_', '', $page );


		// Получаем данные предмета
		$all_subjects    = $this->subjects->read_all();
		$current_subject = $all_subjects[ $key ] ?? null;

		if ( ! $current_subject ) {
			echo "Предмет не найден";

			return;
		}


		// 1. Получаем список типов заданий (термов таксономии) для Менеджера заданий
		$task_types = $this->get_task_types_from_tax( $key );

		// 2. Получаем список визуальных шаблонов из MetaBoxController
		$all_templates = apply_filters( 'fs_lms_get_templates', [] );

		// 3. Получаем пользовательские таксономии для данного предмета
		$custom_taxes = $this->taxonomies->get_by_subject( $key );

		// Подготавливаем данные для шаблона
		// Тут бы DTO
		$data = [
			'subject_key'   => $key,
			'subject'       => $current_subject,
			'task_types'    => $task_types,     // Для Tab 4
			'all_templates' => $all_templates,  // Для Tab 4
			'tasks_url'     => admin_url( "edit.php?post_type={$key}_tasks" ),
			'articles_url'  => admin_url( "edit.php?post_type={$key}_articles" ),
			'protected_tax' => "{$key}_task_number",
			'taxonomies'    => array_merge(
				[ "{$key}_task_number" => [ 'name' => "Номера заданий ({$current_subject['name']})" ] ],
				$custom_taxes
			)
		];
		// Рендерим шаблон с переданными данными ЗАМЕНИТЬ
		$this->render( 'SubjectTest', $data );
	}

	/**
	 * Получает список типов заданий из таксономии предмета.
	 *
	 * Возвращает массив терминов таксономии "Номера заданий" для указанного предмета.
	 *
	 * @param string $subject_key Ключ предмета (slug)
	 *
	 * @return array<int, array{
	 *     id: int,
	 *     name: string,
	 *     description: string,
	 *     slug: string
	 * }> Список типов заданий
	 */
	public function get_task_types_from_tax( string $subject_key ): array {
		$taxonomy = "{$subject_key}_task_number";

		// Получаем все термины таксономии (Задание 1, Задание 2...)
		$terms = get_terms( [
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'orderby'    => 'slug',      // Сортировка по числовому значению
			'order'      => 'ASC'
		] );

		// Формируем массив данных для передачи в шаблон
		$types = [];
		foreach ( $terms as $term ) {
			$types[] = [
				'id'          => $term->term_id,
				'name'        => $term->name,          // "Задание 1"
				'description' => $term->description,   // "Графы"
				'slug'        => $term->slug           // "1"
			];
		}

		return $types;
	}
}