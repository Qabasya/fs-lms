<?php

declare( strict_types=1 );

namespace Inc\Controllers\Subject;

use Inc\Controllers\System\AjaxController;

use Inc\Callbacks\Subject\SubjectBundleCallbacks;
use Inc\Callbacks\Subject\SubjectCrudCallbacks;
use Inc\Callbacks\Subject\SubjectDataCallbacks;
use Inc\Callbacks\Subject\SubjectImportExportCallbacks;
use Inc\Callbacks\Subject\SubjectPageCallbacks;
use Inc\Callbacks\Subject\SubjectValidationCallbacks;
use Inc\Callbacks\Subject\TaxonomySettingsCallbacks;
use Inc\Callbacks\Task\TemplateCallbacks;
use Inc\Callbacks\Task\TemplateManagerCallbacks;
use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Log\Events\EntityChangedEvent;
use Inc\Enums\Wp\AjaxHook;
use Inc\Enums\Log\EntityType;
use Inc\Enums\Log\LogEvent;
use Inc\Enums\Log\OperationType;
use Inc\Managers\Wp\PostManager;
use Inc\Registrars\SubjectContentRegistrar;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Repositories\OptionsRepositories\TaxonomyRepository;
use Inc\Services\Subject\ContentCacheService;
use Inc\Services\Subject\PostTypeResolver;
use Inc\Services\Subject\TaskNumberTermGuard;
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
	use NumericSorter;

	/**
	 * Конструктор.
	 *
	 * @param SubjectRepository            $subjects                 Репозиторий предметов
	 * @param SubjectContentRegistrar      $content_registrar        Регистрация CPT и таксономий предметов
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
	 * @param TaskNumberTermGuard          $task_number_guard        Валидация терминов таксономии "Номера заданий"
	 * @param SubjectBundleCallbacks       $bundle_callbacks         Коллбеки полного пакета переноса предмета
	 */
	public function __construct(
		private readonly SubjectRepository $subjects,
		private readonly SubjectContentRegistrar $content_registrar,
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
		private readonly TemplateCallbacks $task_page_callbacks,
		private readonly LogEventDispatcherInterface $logEvents,
		private readonly TaskNumberTermGuard $task_number_guard,
		private readonly SubjectBundleCallbacks $bundle_callbacks,
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
		$task_post_types = $this->content_registrar->registerAll( $this->subjects->readAll() );

		// Метабокс «Номера заданий» на экране задания скрыт — номер выбирается в модалке
		if ( ! empty( $task_post_types ) ) {
			add_action(
				'add_meta_boxes',
				static function () use ( $task_post_types ): void {
					foreach ( $task_post_types as $task_cpt ) {
						remove_meta_box( 'tagsdiv-' . PostTypeResolver::subjectFromTaskPostType( $task_cpt ) . '_task_number', $task_cpt, 'side' );
					}
				}
			);
		}

		// Валидация обязательных таксономий при сохранении поста
		add_filter( 'wp_insert_post_data', array( $this->validation_callbacks, 'validateRequiredTaxonomies' ), 10, 2 );

		// Регистрация AJAX-обработчиков (унаследовано из AjaxController)
		parent::register();

		// Настройка числовой сортировки терминов таксономий
		$this->setupTermSorting();

		add_action( 'admin_notices', array( $this->validation_callbacks, 'showEmptyRequiredTaxNotice' ) );
		add_action( 'created_term', array( $this, 'onTermCreated' ), 10, 3 );

		// Валидация терминов "Номера заданий" на нативных экранах WP (edit-tags.php):
		// добавление — pre_insert_term, переименование (в т.ч. быстрое) — edit_terms.
		add_filter( 'pre_insert_term', array( $this->task_number_guard, 'validateInsert' ), 10, 2 );
		add_filter( 'wp_insert_term_data', array( $this->task_number_guard, 'normalizeSlug' ), 10, 2 );
		add_action( 'edit_terms', array( $this->task_number_guard, 'validateUpdate' ), 10, 3 );
		// Удаление номера с привязанными записями: право прячет ссылку, действие — страховка.
		add_filter( 'map_meta_cap', array( $this->task_number_guard, 'restrictDeleteCapability' ), 10, 4 );
		add_action( 'pre_delete_term', array( $this->task_number_guard, 'preventDeleteWithContent' ), 10, 2 );



		// Очистка кеша при сохранении или удалении поста
		// 'save_post' — хук сохранения поста (передаёт ID и объект поста)
		add_action( 'save_post', array( $this->cache_service, 'clearRecentContentCache' ), 10, 2 );

		// 'delete_post' — хук удаления поста (передаёт ID поста)
		add_action( 'delete_post', array( $this->cache_service, 'clearCacheOnDelete' ) );

		// 'template_include' — фильтр для подмены шаблона темы
		add_filter( 'template_include', array( $this->task_page_callbacks, 'loadTaskFrontendTemplate' ) );

		// Кастомный статус «В архиве» для банков контента (жизненный цикл, T1.27).
		add_action( 'init', array( $this, 'registerArchivedStatus' ) );
	}

	/**
	 * Регистрирует кастомный статус публикации `fs_archived` для банков контента.
	 *
	 * Архивный контент убран из селекторов для новых ссылок, но существующие
	 * ссылки на него продолжают резолвиться (пост существует).
	 *
	 * @return void
	 */
	public function registerArchivedStatus(): void {
		register_post_status(
			'fs_archived',
			array(
				'label'                     => 'В архиве',
				'public'                    => false,
				'internal'                  => false,
				'protected'                 => true,
				'exclude_from_search'       => true,
				'show_in_admin_all_list'    => false,
				'show_in_admin_status_list' => true,
				'label_count'               => _n_noop(
					'В архиве <span class="count">(%s)</span>',
					'В архиве <span class="count">(%s)</span>'
				),
			)
		);
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
			array( AjaxHook::ToggleSubjectArchive, $this->crud_callbacks ),
			// Получение данных (таблицы, списки)
			array( AjaxHook::GetPostsTable, $this->data_callbacks ),
			array( AjaxHook::GetTasksByNumber, $this->data_callbacks ),
			array( AjaxHook::GetRecentTasks, $this->data_callbacks ),
			array( AjaxHook::GetRecentArticles, $this->data_callbacks ),
			// Импорт/экспорт предметов
			array( AjaxHook::ExportSubject, $this->import_export_callbacks ),
			array( AjaxHook::ImportSubject, $this->import_export_callbacks ),
			array( AjaxHook::PreviewSubjectImport, $this->import_export_callbacks ),
			// Полный пакет переноса предмета (ZIP)
			array( AjaxHook::ExportSubjectBundle, $this->bundle_callbacks ),
			array( AjaxHook::PreviewSubjectBundle, $this->bundle_callbacks ),
			array( AjaxHook::ImportSubjectBundle, $this->bundle_callbacks ),
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
		// numericSortFilter() — метод трейта NumericSorter: строит фильтр,
		// хук вешаем здесь (регистрация хуков — обязанность контроллера).
		add_filter(
			'get_terms_orderby', // Хук WordPress для изменения сортировки терминов
			$this->numericSortFilter( 't.name', array( self::class, 'isTaskNumberTaxonomyQuery' ) ),
			10,
			2
		);
	}

	/**
	 * Условие для {@see numericSortFilter()}: запрос идёт по таксономии
	 * `{subject}_task_number`. `$args['taxonomy']` может отсутствовать или прийти
	 * пустым массивом (напр. `term_exists()` без явной таксономии) — `reset()` на
	 * пустом массиве даёт `false`, и `str_contains( false, ... )` кидает TypeError
	 * при `strict_types=1`; поэтому пустой случай отсекается до `reset()`.
	 *
	 * @param array $args Query vars запроса терминов (`WP_Term_Query`).
	 */
	private static function isTaskNumberTaxonomyQuery( array $args ): bool {
		$tax = (array) ( $args['taxonomy'] ?? array() );
		if ( array() === $tax ) {
			return false;
		}

		$first = reset( $tax );

		return is_string( $first ) && str_contains( $first, '_task_number' );
	}

	/**
	 * Логирует создание терма в плагинной таксономии.
	 *
	 * @param int    $termId   ID созданного терма
	 * @param int    $ttId     ID term_taxonomy
	 * @param string $taxonomy Слаг таксономии
	 *
	 * @return void
	 */
	public function onTermCreated( int $termId, int $ttId, string $taxonomy ): void {
		$isTaskNumber = str_ends_with( $taxonomy, '_task_number' );

		if ( ! $isTaskNumber ) {
			$allTaxonomies = $this->taxonomies->readAll();
			$isPlugin      = false;
			foreach ( $allTaxonomies as $taxes ) {
				foreach ( $taxes as $dto ) {
					if ( $dto->slug === $taxonomy ) {
						$isPlugin = true;
						break 2;
					}
				}
			}
			if ( ! $isPlugin ) {
				return;
			}
		}

		$term = get_term( $termId, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return;
		}

		$taxObj   = get_taxonomy( $taxonomy );
		$taxLabel = $taxObj ? $taxObj->labels->singular_name : $taxonomy;

		$this->logEvents->dispatch(
			LogEvent::TermCreated,
			new EntityChangedEvent(
				get_current_user_id(),
				OperationType::Create,
				EntityType::Term,
				$termId,
				"{$taxLabel}→{$term->name}"
			)
		);
	}
}
