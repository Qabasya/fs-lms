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

	// =========================================================================
	//                  Публичные методы
	// =========================================================================

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
	 * Точка входа контроллера — регистрирует все его компоненты.
	 *
	 * Вызывается один раз при инициализации плагина. Порядок важен:
	 * сначала вешаем AJAX-обработчики, затем настраиваем сортировку
	 * терминов, и только потом регистрируем CPT и таксономии,
	 * потому что CPT/таксономии опираются на данные из БД.
	 */
	public function register(): void {
		$this->registerAjaxHooks();       // AJAX-обработчики для CRUD (предметы, таксономии)
		$this->setupTermSorting();        // Числовая сортировка терминов (1, 2, 3... а не 1, 10, 2...)
		$this->registerCptsAndTaxonomies(); // CPT и таксономии для каждого предмета из БД
	}

	/**
	 * Коллбек страницы управления конкретным предметом в админке.
	 *
	 * WordPress вызывает этот метод, когда администратор открывает
	 * страницу вида /wp-admin/admin.php?page=fs_subject_math.
	 * Из GET-параметра извлекается ключ предмета, по нему собираются
	 * все нужные данные, и шаблон отрисовывается на экран.
	 */
	public function subjectPage(): void {
		// Получаем slug страницы из URL, например: "fs_subject_math"
		$page = $_GET['page'] ?? '';

		// Убираем префикс "fs_subject_", остаётся только ключ предмета, например: "math"
		$key = str_replace( 'fs_subject_', '', $page );

		// Собираем все данные для шаблона в один DTO-объект
		$dto = $this->prepareSubjectViewData( $key );

		// Если предмет с таким ключом не существует в БД — выводим сообщение и выходим
		if ( ! $dto ) {
			echo "Предмет не найден";

			return;
		}

		// Передаём DTO в шаблон SubjectTest и рисуем страницу
		$this->render( 'SubjectTest', $dto );
	}

	/**
	 * Возвращает список типов заданий (терминов таксономии "номера заданий") для предмета.
	 *
	 * Каждый тип задания — это термин таксономии вида `{subject_key}_task_number`.
	 * Например, для предмета "math" таксономия называется "math_task_number",
	 * а её термины — "Задание 1", "Задание 2" и т.д.
	 *
	 * Метод публичный, потому что его могут вызывать другие части плагина
	 * (например, при построении форм выбора шаблона задания).
	 *
	 * @param string $subject_key Ключ предмета (slug), например: "math"
	 *
	 * @return TaskTypeDTO[] Список DTO-объектов типов заданий; пустой массив, если терминов нет
	 */
	public function getTaskTypesFromTax( string $subject_key ): array {
		// Формируем slug таксономии из ключа предмета
		$taxonomy = "{$subject_key}_task_number";

		// Запрашиваем все термины этой таксономии, включая пустые (без записей),
		// и сортируем по slug, чтобы порядок был предсказуемым
		$terms = get_terms( [
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'orderby'    => 'slug',
			'order'      => 'ASC'
		] );

		// Если WordPress вернул ошибку или терминов нет — возвращаем пустой массив
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return [];
		}

		// Для каждого термина создаём DTO с данными о типе задания
		$types = [];
		foreach ( $terms as $term ) {
			// Получаем привязанный шаблон из метабокса; если не задан — используем стандартный
			$assignment = $this->metaboxes->getAssignment( $subject_key, $term->slug );
			$types[]    = new TaskTypeDTO(
				id: $term->term_id,
				name: $term->name,
				slug: $term->slug,
				description: $term->description,
				current_template: $assignment ? $assignment->template_id : 'standard_task'
			);
		}

		return $types;
	}

	// =========================================================================
	//                  Приватные методы
	// =========================================================================

	/**
	 * Регистрирует AJAX-обработчики для CRUD-операций с предметами и таксономиями.
	 *
	 * Хук wp_ajax_{action} срабатывает, когда авторизованный пользователь
	 * отправляет AJAX-запрос с соответствующим action. Каждый обработчик
	 * живёт в своём классе Callbacks, чтобы не раздувать этот контроллер.
	 */
	private function registerAjaxHooks(): void {
		// --- Операции с предметами ---

		// Создать предмет
		add_action( 'wp_ajax_fs_store_subject', [ $this->subjectCallbacks, 'storeSubject' ] );
		// Обновить предмет
		add_action( 'wp_ajax_fs_update_subject', [ $this->subjectCallbacks, 'updateSubject' ] );
		// Удалить предмет
		add_action( 'wp_ajax_fs_delete_subject', [ $this->subjectCallbacks, 'deleteSubject' ] );
		// Сменить шаблон задания
		add_action( 'wp_ajax_fs_update_term_template', [ $this->subjectCallbacks,	'updateTaskTemplate' ] );

		// --- Операции с таксономиями ---

		// Создать таксономию
		add_action( 'wp_ajax_fs_store_taxonomy', [ $this->taxonomyCallbacks, 'storeTaxonomy' ] );
		// Обновить таксономию
		add_action( 'wp_ajax_fs_update_taxonomy', [ $this->taxonomyCallbacks, 'updateTaxonomy' ] );
		// Удалить таксономию
		add_action( 'wp_ajax_fs_delete_taxonomy', [ $this->taxonomyCallbacks, 'deleteTaxonomy' ] );
	}

	/**
	 * Подключает числовую сортировку для таксономий типа "{subject}_task_number".
	 *
	 * По умолчанию WordPress сортирует термины лексикографически:
	 * "1", "10", "2", "3"... Это неудобно для номеров заданий.
	 * Трейт NumericSorter добавляет сортировку по числовому значению имени:
	 * "1", "2", "3", "10"...
	 *
	 * Фильтр применяется только к таксономиям с суффиксом _task_number,
	 * чтобы не ломать сортировку всех остальных таксономий на сайте.
	 */
	private function setupTermSorting(): void {
		$this->addNumericSort(
			'get_terms_orderby',  // Стандартный WordPress-фильтр для сортировки терминов
			't.name',             // SQL-псевдоним поля имени термина в запросе
			function ( $args ) {
				// Получаем таксономию из аргументов запроса и приводим к массиву
				$tax = (array) ( $args['taxonomy'] ?? [] );

				// Включаем числовую сортировку только для таксономий номеров заданий
				return str_contains( reset( $tax ), '_task_number' );
			}
		);
	}

	/**
	 * Перебирает все предметы из БД и регистрирует для каждого CPT и таксономии.
	 *
	 * После того как все предметы обработаны, вызываем финальную регистрацию
	 * в WordPress — она должна выполниться один раз после добавления всех типов,
	 * поэтому вынесена за пределы цикла.
	 */
	private function registerCptsAndTaxonomies(): void {
		// Загружаем все предметы из базы данных
		$all_subjects = $this->subjects->readAll();

		// Если предметов ещё нет — нечего регистрировать, выходим
		if ( empty( $all_subjects ) ) {
			return;
		}

		// Для каждого предмета добавляем его CPT и таксономии в очередь регистраторов
		foreach ( $all_subjects as $subject ) {
			$this->registerForSubject( $subject );
		}

		// Финальная регистрация: здесь регистраторы вызывают register_post_type()
		// и register_taxonomy() для всего накопленного списка
		$this->cptRegistrar->register();
		$this->taxRegistrar->register();
	}

	/**
	 * Добавляет CPT и таксономии одного предмета в очередь регистраторов.
	 *
	 * Для каждого предмета создаётся:
	 * — CPT для заданий  ({key}_tasks)
	 * — CPT для статей   ({key}_articles)
	 * — Фиксированная таксономия номеров заданий ({key}_task_number) — привязана только к заданиям
	 * — Пользовательские таксономии из БД — привязаны и к заданиям, и к статьям
	 *
	 * Метод только накапливает данные в регистраторах, сама регистрация в WordPress
	 * происходит позже, в registerCptsAndTaxonomies().
	 *
	 * @param object $subject DTO предмета (содержит поля key и name).
	 */
	private function registerForSubject( object $subject ): void {
		$key         = $subject->key;   // Уникальный ключ предмета, например: "math"
		$name        = $subject->name;  // Читаемое название предмета, например: "Математика"
		$task_cpt    = "{$key}_tasks";     // Slug CPT заданий
		$article_cpt = "{$key}_articles";  // Slug CPT статей

		// 1. Регистрация CPT для заданий
		//    Поддерживает только title — у задания нет текстового содержимого,
		//    всё хранится в мета-полях
		$this->cptRegistrar->addStandardType(
			$task_cpt,
			"Задания ($name)",
			"Задание",
			[ 'supports' => [ 'title' ] ]
		);

		// 2. Регистрация CPT для статей
		//    Поддерживает title, редактор и миниатюру — полноценные статьи с содержимым
		$this->cptRegistrar->addStandardType(
			$article_cpt,
			"Статьи ($name)",
			"Статья",
			[ 'supports' => [ 'title', 'editor', 'thumbnail' ] ]
		);

		// 3. Регистрация фиксированной таксономии "Номера заданий"
		//    Эта таксономия создаётся автоматически для каждого предмета и защищена от удаления.
		//    Привязывается только к CPT заданий — статьи не нумеруются.
		$fixed_tax_slug = "{$key}_task_number";
		$this->taxRegistrar->addFixedTaxonomy(
			$fixed_tax_slug,
			[ $task_cpt ],
			"Номера заданий ($name)",
			"Номер задания",
			[
				'public'       => true,
				'show_ui'      => true,
				'show_in_menu' => true,
				'rewrite'      => [ 'slug' => $fixed_tax_slug ],
			]
		);

		// 4. Регистрация пользовательских таксономий из БД
		//    Администратор может создавать свои таксономии для предмета (например, "Тема", "Уровень").
		//    Они привязываются и к заданиям, и к статьям, чтобы можно было фильтровать оба типа.
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

	/**
	 * Собирает все данные для страницы управления предметом и упаковывает их в DTO.
	 *
	 * Метод запрашивает данные из нескольких источников и объединяет их
	 * в один объект SubjectViewDTO, который шаблон получает как единый аргумент.
	 * Это избавляет шаблон от прямых обращений к репозиториям.
	 *
	 * @param string $key Ключ предмета, например: "math"
	 *
	 * @return SubjectViewDTO|null DTO для шаблона или null, если предмет не найден в БД
	 */
	private function prepareSubjectViewData( string $key ): ?SubjectViewDTO {
		// Ищем предмет в БД по ключу; если не нашли — возвращаем null
		$current_subject = $this->subjects->getByKey( $key );
		if ( ! $current_subject ) {
			return null;
		}

		// Получаем типы заданий (термины таксономии номеров) с их текущими шаблонами
		$task_types = $this->getTaskTypesFromTax( $key );

		// Получаем список всех доступных шаблонов заданий через фильтр плагина
		$all_templates = apply_filters( 'fs_lms_get_templates', [] );

		// Получаем пользовательские таксономии предмета из БД
		$custom_taxes = $this->taxonomies->getBySubject( $key );

		// Создаём DTO фиксированной таксономии номеров заданий.
		// Она не хранится в БД пользовательских таксономий, поэтому собираем вручную.
		// Флаг is_protected = true запрещает её удаление в интерфейсе.
		$fixed_tax_dto = new TaxonomyDataDTO(
			slug: "{$key}_task_number",
			name: "Номера заданий ({$current_subject->name})",
			subject_key: $key,
			is_protected: true
		);

		// Объединяем таксономии: фиксированная идёт первой, затем пользовательские
		$taxonomies = array_merge( [ $fixed_tax_dto ], $custom_taxes );

		// Собираем итоговый DTO и возвращаем его в subjectPage()
		return new SubjectViewDTO(
			subject_key: $key,
			subject_data: $current_subject,
			task_types: $task_types,
			all_templates: $all_templates,
			tasks_url: admin_url( "edit.php?post_type={$key}_tasks" ),       // URL списка заданий в админке
			articles_url: admin_url( "edit.php?post_type={$key}_articles" ), // URL списка статей в админке
			protected_tax: "{$key}_task_number",
			taxonomies: $taxonomies
		);
	}
}