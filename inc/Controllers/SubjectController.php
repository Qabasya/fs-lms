<?php

namespace Inc\Controllers;

use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\DTO\TaskTypeDTO;
use Inc\Repositories\SubjectRepository;
use Inc\Repositories\TaxonomyRepository;
use Inc\Shared\Traits\TemplateRenderer;
use Inc\Shared\Traits\NumericSorter;
use Inc\Callbacks\SubjectSettingsCallbacks;
use Inc\Callbacks\TaxonomySettingsCallbacks;
use Inc\DTO\SubjectViewDTO;
use Inc\DTO\TaxonomyDataDTO;
use Inc\Repositories\MetaBoxRepository;
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

	private SubjectRepository $subjects;
	private TaxonomyRepository $taxonomies;
	private MetaBoxRepository $metaboxes;


	private SubjectCPTRegistrar $cptRegistrar;
	private SubjectTaxonomyRegistrar $taxRegistrar;

	private SubjectSettingsCallbacks $subjectCallbacks;
	private TaxonomySettingsCallbacks $taxonomyCallbacks;

	public function __construct(
		SubjectRepository $subjects,
		SubjectCPTRegistrar $cptRegistrar,
		SubjectTaxonomyRegistrar $taxRegistrar,
		TaxonomyRepository $taxonomies,
		SubjectSettingsCallbacks $subjectCallbacks,
		TaxonomySettingsCallbacks $taxonomyCallbacks,
		MetaBoxRepository $metaboxes
	) {
		parent::__construct();
		$this->subjects          = $subjects;
		$this->cptRegistrar      = $cptRegistrar;
		$this->taxRegistrar      = $taxRegistrar;
		$this->taxonomies        = $taxonomies;
		$this->subjectCallbacks  = $subjectCallbacks;
		$this->taxonomyCallbacks = $taxonomyCallbacks;
		$this->metaboxes         = $metaboxes;
	}

	/**
	 * Регистрирует все компоненты контроллера.
	 *
	 * Вызывается один раз при инициализации плагина (в Init.php).
	 *
	 * Процесс регистрации:
	 * 1. Регистрация AJAX-обработчиков для CRUD операций
	 * 2. Настройка сортировки терминов таксономий по числовому значению
	 * 3. Для каждого предмета:
	 *    - Регистрация CPT для заданий и статей
	 *    - Регистрация фиксированной таксономии "Номера заданий"
	 *    - Регистрация пользовательских таксономий из репозитория
	 * 4. Выполнение регистрации через регистраторы
	 *
	 * @return void
	 */
	public function register(): void {
		// ====================== РЕГИСТРАЦИЯ AJAX-ОБРАБОТЧИКОВ ======================
		// Регистрация AJAX-обработчиков для CRUD операций с предметами
		add_action( 'wp_ajax_fs_store_subject', [ $this->subjectCallbacks, 'storeSubject' ] );
		add_action( 'wp_ajax_fs_update_subject', [ $this->subjectCallbacks, 'updateSubject' ] );
		add_action( 'wp_ajax_fs_delete_subject', [ $this->subjectCallbacks, 'deleteSubject' ] );
		add_action( 'wp_ajax_fs_update_term_template', [ $this->subjectCallbacks, 'updateTaskTemplate' ] );

		// Регистрация AJAX-обработчиков для CRUD операций с таксономиями
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
		$all_subjects = $this->subjects->readAll();

		if ( empty( $all_subjects ) ) {
			return;
		}

		// Регистрируем CPT и таксономии для каждого предмета
		foreach ( $all_subjects as $subject ) {
			$key         = $subject->key;
			$name        = $subject->name;
			$task_cpt    = "{$key}_tasks";
			$article_cpt = "{$key}_articles";

			// 1. Регистрация CPT для заданий (только заголовок, без контента)
			$this->cptRegistrar->addStandardType(
				$task_cpt,
				"Задания ($name)",
				"Задание",
				[ 'supports' => [ 'title' ] ]
			);

			// 2. Регистрация CPT для статей (с редактором и картинкой)
			$this->cptRegistrar->addStandardType(
				$article_cpt,
				"Статьи ($name)",
				"Статья",
				[ 'supports' => [ 'title', 'editor', 'thumbnail' ] ]
			);

			// 3. Регистрация фиксированной таксономии "Номера заданий"
			$fixed_tax_slug = "{$key}_task_number";

			$this->taxRegistrar->addFixedTaxonomy(
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
						'manage_terms' => 'manage_categories',
						'edit_terms'   => 'manage_categories',
						'delete_terms' => 'manage_categories',
						'assign_terms' => 'edit_posts',
					],
				]
			);

			// 4. Регистрация пользовательских таксономий из репозитория
			$custom_taxes = $this->taxonomies->getBySubject( $key );

			foreach ( $custom_taxes as $tax_dto ) {
				$this->taxRegistrar->addStandardTaxonomy(
					$tax_dto->slug,
					[ $task_cpt, $article_cpt ],
					$tax_dto->name,
					$tax_dto->name
				);
			}
		}

		// Выполняем регистрацию всех накопленных CPT и таксономий
		$this->cptRegistrar->register();
		$this->taxRegistrar->register();
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

		// Получаем данные предмета в виде DTO
		$current_subject = $this->subjects->getByKey( $key );

		if ( ! $current_subject ) {
			echo "Предмет не найден";

			return;
		}

		// 1. Получаем список типов заданий (термов таксономии) для менеджера заданий
		$task_types = $this->getTaskTypesFromTax( $key );

		// 2. Получаем список визуальных шаблонов из MetaBoxController
		$all_templates = apply_filters( 'fs_lms_get_templates', [] );

		// 3. Получаем пользовательские таксономии для данного предмета
		$custom_taxes = $this->taxonomies->getBySubject( $key );

		// Создаём DTO для системной таксономии "Номера заданий", чтобы всё было однородным
		$fixed_tax_dto = new TaxonomyDataDTO(
			slug: "{$key}_task_number",
			name: "Номера заданий ({$current_subject->name})",
			subject_key: $key,
			is_protected: true
		);

		// Объединяем системную и пользовательские таксономии
		$taxonomies = array_merge( [ $fixed_tax_dto ], $custom_taxes );

		// Создаём DTO для передачи всех данных в шаблон
		$dto = new SubjectViewDTO(
			subject_key: $key,
			subject_data: $current_subject,
			task_types: $task_types,
			all_templates: $all_templates,
			tasks_url: admin_url( "edit.php?post_type={$key}_tasks" ),
			articles_url: admin_url( "edit.php?post_type={$key}_articles" ),
			protected_tax: "{$key}_task_number",
			taxonomies: $taxonomies  // Массив объектов TaxonomyDataDTO
		);

		// Рендерим шаблон с переданными данными
		$this->render( 'SubjectTest', $dto );
	}

	/**
	 * Получает список типов заданий из таксономии предмета.
	 *
	 * Возвращает массив DTO-объектов терминов таксономии "Номера заданий"
	 * для указанного предмета.
	 *
	 * @param string $subject_key Ключ предмета (slug)
	 *
	 * @return TaskTypeDTO[] Список DTO-объектов типов заданий
	 */
	public function getTaskTypesFromTax( string $subject_key ): array {
		$taxonomy = "{$subject_key}_task_number";

		// Получаем все термины таксономии (Задание 1, Задание 2...)
		$terms = get_terms( [
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'orderby'    => 'slug',      // Сортировка по числовому значению
			'order'      => 'ASC'
		] );

		// Обрабатываем возможные ошибки WP_Error
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return [];
		}

		// Формируем массив DTO-объектов для передачи в шаблон
		$types = [];
		foreach ( $terms as $term ) {
			// Получаем привязку шаблона к данному типу задания
			$assignment = $this->metaboxes->getAssignment( $subject_key, $term->slug );

			$types[] = new TaskTypeDTO(
				id: $term->term_id,
				name: $term->name,
				slug: $term->slug,
				description: $term->description,
				current_template: $assignment ? $assignment->template_id : 'standard_task'
			);
		}

		return $types;
	}
}