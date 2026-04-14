<?php

namespace Inc\Controllers;

use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
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
class SubjectController extends BaseController implements ServiceInterface
{
	use TemplateRenderer;
	use NumericSorter;

	/**
	 * Конструктор.
	 *
	 * @param SubjectRepository           $subjects          Репозиторий предметов
	 * @param SubjectCPTRegistrar         $cptRegistrar      Регистратор CPT
	 * @param SubjectTaxonomyRegistrar    $taxRegistrar      Регистратор таксономий
	 * @param TaxonomyRepository          $taxonomies        Репозиторий таксономий
	 * @param SubjectSettingsCallbacks    $subjectCallbacks  Коллбеки для предметов
	 * @param TaxonomySettingsCallbacks   $taxonomyCallbacks Коллбеки для таксономий
	 * @param TemplateManagerCallbacks    $templateCallbacks Коллбеки для шаблонов
	 * @param MetaBoxRepository           $metaboxes         Репозиторий метабоксов
	 */
	public function __construct(
		private readonly SubjectRepository $subjects,
		private readonly SubjectCPTRegistrar $cptRegistrar,
		private readonly SubjectTaxonomyRegistrar $taxRegistrar,
		private readonly TaxonomyRepository $taxonomies,
		private readonly SubjectSettingsCallbacks $subjectCallbacks,
		private readonly TaxonomySettingsCallbacks $taxonomyCallbacks,
		private readonly TemplateManagerCallbacks $templateCallbacks,
		private readonly MetaBoxRepository $metaboxes,
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
	public function register(): void
	{
		// Регистрация AJAX-обработчиков
		$this->registerAjaxHooks();

		// Настройка числовой сортировки терминов таксономий
		$this->setupTermSorting();

		// Регистрация CPT и таксономий для всех предметов
		$this->registerCptsAndTaxonomies();
	}

	/**
	 * Коллбек страницы управления конкретным предметом в админке.
	 *
	 * Вызывается WordPress при открытии /wp-admin/admin.php?page=fs_subject_{key}.
	 *
	 * @return void
	 */
	public function subjectPage(): void
	{
		$page = sanitize_text_field(wp_unslash($_GET['page'] ?? ''));
		$key  = str_replace('fs_subject_', '', $page);

		$dto = $this->prepareSubjectViewData($key);

		if (!$dto) {
			echo 'Предмет не найден';
			return;
		}

		$this->render('SubjectTest', $dto);
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
	private function registerAjaxHooks(): void
	{
		// --- Предметы -> subjects.js & SubjectSettingsCallbacks ---
		add_action('wp_ajax_store_subject',  [$this->subjectCallbacks, 'storeSubject']);
		add_action('wp_ajax_update_subject', [$this->subjectCallbacks, 'updateSubject']);
		add_action('wp_ajax_delete_subject', [$this->subjectCallbacks, 'deleteSubject']);

		// --- Таксономии -> нет файла JS & TaxonomySettingsCallbacks---
		add_action( 'wp_ajax_store_taxonomy',  [ $this->taxonomyCallbacks, 'storeTaxonomy' ] );
		add_action( 'wp_ajax_update_taxonomy', [ $this->taxonomyCallbacks, 'updateTaxonomy' ] );
		add_action( 'wp_ajax_delete_taxonomy', [ $this->taxonomyCallbacks, 'deleteTaxonomy' ] );

		// --- Шаблоны -> tasks.js & TemplateManagerCallbacks ---
		add_action('wp_ajax_update_term_template', [$this->templateCallbacks, 'updateTaskTemplate' ]);
	}

	/**
	 * Подключает числовую сортировку для таксономий вида "{subject}_task_number".
	 *
	 * Без неё WordPress сортирует термины лексикографически: 1, 10, 2, 3...
	 * Трейт NumericSorter исправляет порядок на числовой: 1, 2, 3, 10...
	 *
	 * @return void
	 */
	private function setupTermSorting(): void
	{
		$this->addNumericSort(
			'get_terms_orderby',
			't.name',
			static function ($args): bool {
				$tax = (array) ($args['taxonomy'] ?? []);
				return str_contains(reset($tax), '_task_number');
			}
		);
	}

	/**
	 * Перебирает все предметы из БД и регистрирует для каждого CPT и таксономии.
	 *
	 * @return void
	 */
	private function registerCptsAndTaxonomies(): void
	{
		$all_subjects = $this->subjects->readAll();

		if (empty($all_subjects)) {
			return;
		}

		// Регистрация CPT и таксономий для каждого предмета
		foreach ($all_subjects as $subject) {
			$this->registerForSubject($subject);
		}

		// Выполнение регистрации всех накопленных CPT и таксономий
		$this->cptRegistrar->register();
		$this->taxRegistrar->register();
	}

	/**
	 * Добавляет CPT и таксономии одного предмета в очередь регистраторов.
	 *
	 * Для каждого предмета создаётся:
	 * — CPT для заданий  ({key}_tasks)     — только title
	 * — CPT для статей   ({key}_articles)  — title, editor, thumbnail
	 * — Фиксированная таксономия номеров заданий ({key}_task_number)
	 * — Пользовательские таксономии из БД
	 *
	 * @param object $subject DTO предмета (содержит поля key и name)
	 *
	 * @return void
	 */
	private function registerForSubject(object $subject): void
	{
		$key         = $subject->key;
		$name        = $subject->name;
		$task_cpt    = "{$key}_tasks";
		$article_cpt = "{$key}_articles";

		// Регистрация CPT для заданий (только заголовок)
		$this->cptRegistrar->addStandardType(
			$task_cpt,
			"Задания ($name)",
			'Задание',
			['supports' => ['title']]
		);

		// Регистрация CPT для статей (с редактором и картинкой)
		$this->cptRegistrar->addStandardType(
			$article_cpt,
			"Статьи ($name)",
			'Статья',
			['supports' => ['title', 'editor', 'thumbnail']]
		);

		// Регистрация фиксированной таксономии "Номера заданий"
		$fixed_tax_slug = "{$key}_task_number";
		$this->taxRegistrar->addFixedTaxonomy(
			$fixed_tax_slug,
			[$task_cpt],
			"Номера заданий: ($name)",
			'Номер задания',
			[
				'public'       => true,
				'show_ui'      => true, // проверить
				'meta_box_cb' => '__return_false',
				'show_in_menu' => true,
				'rewrite'      => ['slug' => $fixed_tax_slug],
			]
		);

		// Регистрация пользовательских таксономий из репозитория
		foreach ($this->taxonomies->getBySubject($key) as $tax_dto) {
			$this->taxRegistrar->addStandardTaxonomy(
				$tax_dto->slug,
				[$task_cpt, $article_cpt],
				$tax_dto->name,
				$tax_dto->name
			);
		}
	}

	/**
	 * Собирает все данные для страницы управления предметом и упаковывает в SubjectViewDTO.
	 *
	 * @param string $key Ключ предмета, например: "math"
	 *
	 * @return SubjectViewDTO|null DTO для шаблона или null, если предмет не найден
	 */
	private function prepareSubjectViewData(string $key): ?SubjectViewDTO
	{
		$current_subject = $this->subjects->getByKey($key);

		if (!$current_subject) {
			return null;
		}

		// Фиксированная таксономия номеров — не хранится в БД пользовательских таксономий,
		// поэтому собираем вручную. Флаг is_protected запрещает удаление в интерфейсе.
		$fixed_tax_dto = new TaxonomyDataDTO(
			slug: "{$key}_task_number",
			name: "Номера заданий ({$current_subject->name})",
			subject_key: $key,
			is_protected: true
		);

		// Создаём DTO для передачи всех данных в шаблон
		return new SubjectViewDTO(
			subject_key: $key,
			subject_data: $current_subject,
			task_types: $this->metaboxes->getTaskTypes($key),
			all_templates: apply_filters('fs_lms_get_templates', []),
			tasks_url: admin_url("edit.php?post_type={$key}_tasks"),
			articles_url: admin_url("edit.php?post_type={$key}_articles"),
			protected_tax: "{$key}_task_number",
			taxonomies: array_merge([$fixed_tax_dto], $this->taxonomies->getBySubject($key))
		);
	}
}