<?php

namespace Inc\Controllers;

use Inc\Callbacks\SubjectCrudCallbacks;
use Inc\Callbacks\SubjectDataCallbacks;
use Inc\Callbacks\SubjectImportExportCallbacks;
use Inc\Callbacks\SubjectPageCallbacks;
use Inc\Callbacks\SubjectValidationCallbacks;
use Inc\Callbacks\TaxonomySettingsCallbacks;
use Inc\Callbacks\TemplateCallbacks;
use Inc\Callbacks\TemplateManagerCallbacks;
use Inc\Enums\AjaxHook;
use Inc\Managers\PostManager;
use Inc\Registrars\SubjectCPTRegistrar;
use Inc\Registrars\SubjectTaxonomyRegistrar;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Repositories\OptionsRepositories\TaxonomyRepository;
use Inc\Services\ContentCacheService;
use Inc\Services\PostTypeResolver;
use Inc\Shared\Traits\NumericSorter;

/**
 * Class SubjectController
 *
 * Контроллер для управления предметами и связанными с ними CPT.
 *
 * @package Inc\Controllers
 *
 * ### Основные обязанности:
 *
 * 1. **Регистрация CPT** — динамически создаёт типы постов "задания" и "статьи" для каждого предмета.
 * 2. **Регистрация таксономий** — подключает фиксированную таксономию номеров заданий и пользовательские таксономии.
 * 3. **Регистрация AJAX-хуков** — подключает обработчики CRUD, данных, импорта/экспорта, таксономий и шаблонов.
 * 4. **Настройка сортировки** — реализует числовую сортировку для таксономии номеров заданий.
 * 5. **Кеширование** — очищает кеш при сохранении/удалении постов.
 *
 * ### Архитектурная роль:
 *
 * Делегирует регистрацию CPT и таксономий специализированным регистраторам, а AJAX-логику — коллбекам.
 */
class SubjectController extends AjaxController {
	use NumericSorter;  // Трейт с методом addNumericSort() для числовой сортировки терминов

	/**
	 * Конструктор.
	 *
	 * @param SubjectRepository            $subjects                 Репозиторий предметов
	 * @param SubjectCPTRegistrar          $cpt_registrar            Регистратор CPT
	 * @param SubjectTaxonomyRegistrar     $tax_registrar            Регистратор таксономий
	 * @param TaxonomyRepository           $taxonomies               Репозиторий таксономий
	 * @param SubjectCrudCallbacks         $crud_callbacks           Коллбеки CRUD
	 * @param SubjectDataCallbacks         $data_callbacks           Коллбеки получения данных
	 * @param SubjectImportExportCallbacks $import_export_callbacks  Коллбеки импорта/экспорта
	 * @param TaxonomySettingsCallbacks    $taxonomy_callbacks       Коллбеки таксономий
	 * @param TemplateManagerCallbacks     $template_callbacks       Коллбеки шаблонов
	 * @param PostManager                  $posts                    Менеджер постов
	 * @param SubjectPageCallbacks         $page_callbacks           Коллбеки страниц
	 * @param SubjectValidationCallbacks   $validation_callbacks     Коллбеки валидации
	 * @param ContentCacheService          $cache_service            Сервис кеширования
	 * @param TemplateCallbacks            $task_page_callbacks      Коллбеки фронтенда заданий
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
		private readonly TemplateCallbacks $task_page_callbacks
	) {
		parent::__construct();
	}

	// ============================ ПУБЛИЧНЫЕ МЕТОДЫ ============================ //

	/**
	 * Точка входа контроллера — регистрирует все его компоненты.
	 *
	 * @return void
	 */
	public function register(): void {
		// Регистрация CPT и таксономий для всех предметов
		$this->registerCptsAndTaxonomies();

		// Регистрация AJAX-обработчиков (унаследовано из AjaxController)
		$this->registerAjaxHooks();

		// Настройка числовой сортировки терминов таксономий
		$this->setupTermSorting();

		// 'admin_notices' — хук для вывода уведомлений в админ-панели
		add_action( 'admin_notices', array( $this->page_callbacks, 'showRequiredTaxNotice' ) );

		// Очистка кеша при сохранении или удалении поста
		// 'save_post' — хук сохранения поста (передаёт ID и объект поста)
		add_action( 'save_post', array( $this->cache_service, 'clearRecentContentCache' ), 10, 2 );

		// 'delete_post' — хук удаления поста (передаёт ID поста)
		add_action( 'delete_post', array( $this->cache_service, 'clearCacheOnDelete' ) );

		// 'template_include' — фильтр для подмены шаблона темы
		add_filter( 'template_include', array( $this->task_page_callbacks, 'loadTaskFrontendTemplate' ) );
	}

	// ============================ AJAX-ДЕЙСТВИЯ ============================ //

	/**
	 * Возвращает список AJAX-действий для регистрации (только для авторизованных пользователей).
	 *
	 * @return array Массив действий, каждое с хуком и объектом-коллбеком
	 */
	protected function ajaxActions(): array {
		return array(
			// CRUD-операции с предметами
			array( AjaxHook::StoreSubject, $this->crud_callbacks ),
			array( AjaxHook::UpdateSubject, $this->crud_callbacks ),
			array( AjaxHook::DeleteSubject, $this->crud_callbacks ),
			// Получение данных (таблицы, списки)
			array( AjaxHook::GetPostsTable, $this->data_callbacks ),
			array( AjaxHook::GetTasksByNumber, $this->data_callbacks ),
			array( AjaxHook::GetRecentTasks, $this->data_callbacks ),
			array( AjaxHook::GetRecentArticles, $this->data_callbacks ),
			// Импорт/экспорт предметов
			array( AjaxHook::ExportSubject, $this->import_export_callbacks ),
			array( AjaxHook::ImportSubject, $this->import_export_callbacks ),
			// Управление таксономиями
			array( AjaxHook::StoreTaxonomy, $this->taxonomy_callbacks ),
			array( AjaxHook::UpdateTaxonomy, $this->taxonomy_callbacks ),
			array( AjaxHook::DeleteTaxonomy, $this->taxonomy_callbacks ),
			// Управление шаблонами метабоксов
			array( AjaxHook::UpdateTermTemplate, $this->template_callbacks ),
		);
	}

	// ============================ ПРИВАТНЫЕ МЕТОДЫ ============================ //

	/**
	 * Подключает числовую сортировку для таксономий вида "{subject}_task_number".
	 *
	 * @return void
	 */
	private function setupTermSorting(): void {
		// addNumericSort() — метод трейта NumericSorter
		$this->addNumericSort(
			'get_terms_orderby',    // Хук WordPress для изменения сортировки терминов
			't.name',               // Поле для сортировки
			static function ( $args ): bool {
				$tax = (array) ( $args['taxonomy'] ?? array() );
				// str_contains() — проверяет наличие подстроки '_task_number'
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
	 * @param object $subject DTO предмета (содержит поля key и name)
	 *
	 * @return void
	 */
	private function registerForSubject( object $subject ): void {
		$key         = $subject->key;
		$task_cpt    = PostTypeResolver::tasks( $key );
		$article_cpt = PostTypeResolver::articles( $key );

		// 1. Регистрация Заданий (только заголовок)
		$task_args = $this->getDefaultCptArgs( 'tasks', $subject );
		$this->cpt_registrar->addStandardType(
			$task_cpt,
			'Задания',
			$task_args['labels'],
			$task_args['options']
		);

		// 2. Регистрация Статей (заголовок, редактор, миниатюра)
		$article_args = $this->getDefaultCptArgs( 'articles', $subject );
		$this->cpt_registrar->addStandardType(
			$article_cpt,
			'Статьи',
			$article_args['labels'],
			$article_args['options']
		);

		// 3. Регистрация фиксированной таксономии "Номера заданий"
		$fixed_tax_slug = "{$key}_task_number";
		$this->tax_registrar->addFixedTaxonomy(
			$fixed_tax_slug,
			array( $task_cpt, $article_cpt ),
			'Номера заданий',
			'Номер задания',
			array(
				'public'       => true,
				'show_ui'      => true,
				// buildMetaBoxCallback() — создаёт коллбек для отображения метабокса
				'meta_box_cb'  => $this->tax_registrar->buildMetaBoxCallback( 'select' ),
				'show_in_menu' => true,
				'rewrite'      => array( 'slug' => $fixed_tax_slug ),
			)
		);

		// remove_meta_box() — удаляет стандартный метабокс таксономии
		// Для заданий скрываем метабокс (выбор номера через модальное окно)
		add_action(
			'add_meta_boxes',
			static function () use ( $task_cpt, $fixed_tax_slug ): void {
				remove_meta_box( "tagsdiv-{$fixed_tax_slug}", $task_cpt, 'side' );
			}
		);

		// 4. Регистрация пользовательских таксономий (только для заданий)
		foreach ( $this->taxonomies->getBySubject( $key ) as $tax_dto ) {
			$this->tax_registrar->addStandardTaxonomy(
				$tax_dto->slug,
				array( $task_cpt ),
				$tax_dto->name,
				$tax_dto->name,
				$tax_dto->display_type
			);
		}

		// 5. Фильтр для валидации обязательных таксономий при сохранении поста
		add_filter( 'wp_insert_post_data', array( $this->validation_callbacks, 'validateRequiredTaxonomies' ), 10, 2 );
	}

	/**
	 * Формирует дефолтную конфигурацию для CPT и прогоняет её через фильтр.
	 *
	 * @param string $type    Тип (tasks|articles)
	 * @param object $subject DTO предмета
	 *
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
		 * apply_filters() — позволяет модифицировать аргументы CPT через сторонние плагины.
		 *
		 * @param array  $args    Массив с labels и options
		 * @param string $type    Тип контента (tasks или articles)
		 * @param object $subject Объект предмета
		 */
		return apply_filters( 'fs_lms_cpt_args', $args, $type, $subject );
	}
}