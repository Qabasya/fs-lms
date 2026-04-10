<?php

namespace Inc\Callbacks;

use Inc\Core\BaseController;
use Inc\Controllers\SubjectController;
use Inc\Repositories\MetaBoxRepository;
use Inc\Repositories\TaskTypeRepository;

/**
 * Class TaskCreationCallbacks
 *
 * Обработчики AJAX-запросов для создания и управления заданиями.
 *
 * @package Inc\Callbacks
 */
class TaskCreationCallbacks extends BaseController {
	/**
	 * Маппинг ID шаблона → FQCN класса шаблона.
	 * Добавлять новые шаблоны только сюда — методы трогать не нужно.
	 *
	 * @var array<string, string>
	 */
	private const TEMPLATES_MAP = [
		'standard_task'        => \Inc\MetaBoxes\Templates\StandardTaskTemplate::class,
		'triple_task'          => \Inc\MetaBoxes\Templates\ThreeInOneTemplate::class,
		'common_standard_task' => \Inc\MetaBoxes\Templates\CommonConditionTemplate::class,
	];

	/**
	 * ID шаблона по умолчанию.
	 *
	 * @var string
	 */
	private const DEFAULT_TEMPLATE = 'standard_task';

	/**
	 * Контроллер предметов для доступа к методам получения типов заданий.
	 *
	 * @var SubjectController
	 */
	private SubjectController $subjectController;

	/**
	 * Репозиторий для работы с привязками заданий к шаблонам.
	 *
	 * @var MetaBoxRepository
	 */
	private MetaBoxRepository $metaboxes;

	/**
	 * Репозиторий для работы с типами заданий (boilerplate).
	 *
	 * @var TaskTypeRepository
	 */
	private TaskTypeRepository $taskTypes;

	/**
	 * Конструктор.
	 *
	 * @param SubjectController $subjectController Контроллер предметов
	 * @param MetaBoxRepository $metaboxes Репозиторий привязок шаблонов
	 * @param TaskTypeRepository $taskTypes Репозиторий типов заданий
	 */
	public function __construct(
		SubjectController $subjectController,
		MetaBoxRepository $metaboxes,
		TaskTypeRepository $taskTypes
	) {
		parent::__construct();
		$this->subjectController = $subjectController;
		$this->metaboxes         = $metaboxes;
		$this->taskTypes         = $taskTypes;
	}

	// ============================ AJAX-КОЛЛБЕКИ ============================ //

	/**
	 * Получение типов заданий для выпадающего списка в модальном окне.
	 *
	 * @return void
	 */
	public function ajaxGetTypes(): void {
		// Проверка nonce для защиты от CSRF
		check_ajax_referer( 'fs_task_creation_nonce', 'nonce' );

		// Проверка прав доступа (редактирование постов)
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( 'Доступ запрещён', 403 );
		}

		// Получение и санитизация ключа предмета
		$subject_key = sanitize_text_field( $_GET['subject_key'] ?? '' );

		if ( empty( $subject_key ) ) {
			wp_send_json_error( 'Предмет не указан' );
		}

		// Получение типов заданий через контроллер
		$types = $this->subjectController->getTaskTypesFromTax( $subject_key );

		wp_send_json_success( $types );
	}

	/**
	 * Основной метод создания нового задания.
	 *
	 * @return void
	 */
	public function ajaxCreateTask(): void {
		// Валидация запроса
		$this->validateCreateTaskRequest();

		// Сбор данных из POST
		[ $subject_key, $term_id, $title ] = $this->collectCreateTaskData();

		$taxonomy = "{$subject_key}_task_number";
		$term     = get_term( $term_id, $taxonomy );

		// Проверка существования термина
		if ( ! $term || is_wp_error( $term ) ) {
			wp_send_json_error( 'Тип задания не найден' );

			return;
		}

		$term_slug   = (string) $term->slug;
		$boilerplate = $this->taskTypes->getBoilerplate( $subject_key, $term_slug );
		$task_text   = ( $boilerplate && ! empty( $boilerplate->text ) ) ? $boilerplate->text : '';

		// Создание поста задания
		$new_id = $this->insertTaskPost( $subject_key, $taxonomy, $term_id, $term, $term_slug, $title, $task_text );

		if ( is_wp_error( $new_id ) ) {
			wp_send_json_error( 'Ошибка базы данных: ' . $new_id->get_error_message() );

			return;
		}

		// Применение мета-данных к созданному посту
		$this->applyPostMeta( $new_id, $subject_key, $term_slug, $term_id, $taxonomy, $task_text );

		wp_send_json_success( [ 'redirect' => get_edit_post_link( $new_id, 'abs' ) ] );
	}

	/**
	 * Сохранение настроек шаблона в Менеджере заданий.
	 *
	 * @return void
	 */
	public function ajaxSaveTemplateAssignment(): void {
		// Проверка nonce
		check_ajax_referer( 'fs_lms_manager_nonce', 'nonce' );

		// Проверка прав доступа (управление настройками)
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Доступ запрещен' );

			return;
		}

		// Сбор и санитизация данных
		$subject_key = sanitize_text_field( wp_unslash( $_POST['subject_key'] ?? '' ) );
		$task_number = sanitize_text_field( wp_unslash( $_POST['task_number'] ?? '' ) );
		$template_id = sanitize_text_field( wp_unslash( $_POST['template_id'] ?? '' ) );

		if ( empty( $subject_key ) || empty( $task_number ) ) {
			wp_send_json_error( 'Некорректные данные' );

			return;
		}

		// Сохранение привязки через репозиторий
		$this->metaboxes->updateAssignment( $subject_key, $task_number, $template_id );

		wp_send_json_success( [ 'message' => 'Настройки сохранены' ] );
	}

	/**
	 * AJAX-метод: Получает структуру полей шаблона для конкретного типа задания.
	 *
	 * @return void
	 */
	public function ajaxGetTemplateStructure(): void {
		// Сбор и санитизация данных
		$subject_key = sanitize_text_field( wp_unslash( $_GET['subject_key'] ?? '' ) );
		$term_slug   = sanitize_text_field( wp_unslash( $_GET['term_slug'] ?? '' ) );

		// Определение ID шаблона
		$assignment  = $this->metaboxes->getAssignment( $subject_key, $term_slug );
		$template_id = $assignment->template_id ?? self::DEFAULT_TEMPLATE;
		$class_name  = self::TEMPLATES_MAP[ $template_id ] ?? self::TEMPLATES_MAP[ self::DEFAULT_TEMPLATE ];

		try {
			/** @var \Inc\MetaBoxes\Templates\BaseTemplate $template_obj */
			$template_obj = new $class_name();

			// Проверка наличия метода get_fields
			if ( ! method_exists( $template_obj, 'get_fields' ) ) {
				wp_send_json_error( "В классе {$class_name} отсутствует метод get_fields()" );

				return;
			}

			// Фильтрация полей: оставляем только ConditionField (условия)
			$fields = array_filter(
				$template_obj->get_fields(),
				static fn( $config ) => isset( $config['object'] )
				                        && $config['object'] instanceof \Inc\MetaBoxes\Fields\ConditionField
			);

			// Преобразование структуры полей для передачи на клиент
			$structure = [];
			foreach ( $fields as $key => $config ) {
				// Генерируем уникальный ID для TinyMCE (только буквы и подчеркивания)
				$editor_id = 'tinymce_' . strtolower( preg_replace( '/[^a-z0-9_]/i', '_', $key ) );

				$structure[] = [
					'id'    => $key,
					'label' => $config['label'],
					// Передаем HTML-заготовку, которую "оживит" JS
					'html'  => sprintf(
						'<div class="fs-lms-boilerplate-editor-container">
                    <textarea id="%s" class="js-boilerplate-editor" data-field-key="%s" rows="10" style="width:100%%;"></textarea>
                 </div>',
						esc_attr( $editor_id ),
						esc_attr( $key )
					)
				];
			}

			wp_send_json_success( [ 'fields' => array_values( $structure ) ] );
		} catch ( \Throwable $e ) {
			wp_send_json_error( 'Ошибка PHP: ' . $e->getMessage() );
		}
	}

	// ============================ ПРИВАТНЫЕ МЕТОДЫ ============================ //

	/**
	 * Валидация запроса на создание задания: nonce и права.
	 *
	 * @return void
	 */
	private function validateCreateTaskRequest(): void {
		check_ajax_referer( 'fs_task_creation_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( 'У вас недостаточно прав для создания задания', 403 );
		}
	}

	/**
	 * Сбор и валидация POST-данных для создания задания.
	 *
	 * @return array{0: string, 1: int, 2: string} [subject_key, term_id, title]
	 */
	private function collectCreateTaskData(): array {
		$subject_key = sanitize_text_field( wp_unslash( $_POST['subject_key'] ?? '' ) );
		$term_id     = absint( $_POST['term_id'] ?? 0 );
		$title       = sanitize_text_field( wp_unslash( $_POST['title'] ?? 'Новое задание' ) );

		if ( empty( $subject_key ) || $term_id === 0 ) {
			wp_send_json_error( 'Недостаточно данных' );
			// wp_send_json_error завершает выполнение через wp_die(),
			// return нужен только для анализаторов кода (psalm/phpstan)
			return [];
		}

		return [ $subject_key, $term_id, $title ];
	}

	/**
	 * Создаёт пост задания и привязывает его к таксономии.
	 *
	 * @param string $subject_key Ключ предмета
	 * @param string $taxonomy Имя таксономии
	 * @param int $term_id ID термина
	 * @param \WP_Term $term Объект термина
	 * @param string $term_slug Слаг термина
	 * @param string $title Заголовок задания
	 * @param string $task_text Текст задания (boilerplate)
	 *
	 * @return int|\WP_Error ID созданного поста или ошибка
	 */
	private function insertTaskPost(
		string $subject_key,
		string $taxonomy,
		int $term_id,
		\WP_Term $term,
		string $term_slug,
		string $title,
		string $task_text
	) {
		// Генерация уникального slug для поста
		$type_prefix   = $this->extractNumberFromSlug( $term_slug ) ?: $term->term_id;
		$current_count = $this->getExistingTasksCount( $subject_key, $taxonomy, $term_id );
		$custom_slug   = $type_prefix . str_pad( (string) $current_count, 3, '0', STR_PAD_LEFT );

		// Формирование контента для редактора
		$display_content = $this->resolveDisplayContent( $task_text );

		// Создание поста
		$new_id = wp_insert_post( [
			'post_title'   => $title,
			'post_name'    => $custom_slug,
			'post_type'    => "{$subject_key}_tasks",
			'post_status'  => 'draft',
			'post_author'  => get_current_user_id(),
			'post_content' => $display_content,
		], true );

		// Привязка к таксономии (если пост создан успешно)
		if ( ! is_wp_error( $new_id ) ) {
			wp_set_object_terms( $new_id, $term_id, $taxonomy );
		}

		return $new_id;
	}

	/**
	 * Применяет мета-данные к созданному посту:
	 * шаблон, fs_lms_meta с boilerplate.
	 *
	 * @param int $new_id ID созданного поста
	 * @param string $subject_key Ключ предмета
	 * @param string $term_slug Слаг термина
	 * @param int $term_id ID термина
	 * @param string $taxonomy Имя таксономии
	 * @param string $task_text Текст задания (boilerplate)
	 *
	 * @return void
	 */
	private function applyPostMeta(
		int $new_id,
		string $subject_key,
		string $term_slug,
		int $term_id,
		string $taxonomy,
		string $task_text
	): void {
		// Назначение визуального шаблона
		$assignment = $this->metaboxes->getAssignment( $subject_key, $term_slug );
		$template   = $assignment->template_id ?? self::DEFAULT_TEMPLATE;
		update_post_meta( $new_id, '_fs_lms_template_type', $template );

		// Сохранение данных boilerplate в fs_lms_meta (метабоксы читают отсюда)
		if ( empty( $task_text ) ) {
			return;
		}

		update_post_meta( $new_id, 'fs_lms_meta', $this->buildMetaFromBoilerplate( $task_text ) );
		clean_post_cache( $new_id );
	}

	/**
	 * Декодирует boilerplate-текст и возвращает массив для fs_lms_meta.
	 *
	 * @param string $task_text Текст задания (JSON или обычный текст)
	 *
	 * @return array<string, string> Массив мета-данных
	 */
	private function buildMetaFromBoilerplate( string $task_text ): array {
		$clean   = wp_unslash( $task_text );
		$decoded = json_decode( $clean, true );

		if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
			// array union: добавляет task_answer только если его нет в $decoded
			return $decoded + [ 'task_answer' => '' ];
		}

		return [
			'task_condition' => $clean,
			'task_answer'    => '',
		];
	}

	/**
	 * Формирует display-контент для post_content редактора.
	 * Если boilerplate — JSON-массив, склеивает части через двойной перенос строки.
	 *
	 * @param string $task_text Текст задания (boilerplate)
	 *
	 * @return string Контент для post_content
	 */
	private function resolveDisplayContent( string $task_text ): string {
		if ( empty( $task_text ) ) {
			return '';
		}

		$decoded = json_decode( wp_unslash( $task_text ), true );

		if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
			return implode( "\n\n", $decoded );
		}

		return $task_text;
	}

	/**
	 * Извлекает числовой суффикс из слага термина.
	 * Например: 'inf_5' → 5, 'task' → 0.
	 *
	 * @param string $slug Слаг термина
	 *
	 * @return int Числовой суффикс или 0
	 */
	private function extractNumberFromSlug( string $slug ): int {
		return preg_match( '/(\d+)$/', $slug, $matches ) ? (int) $matches[1] : 0;
	}

	/**
	 * Считает количество существующих заданий данного типа.
	 * Используется для генерации порядкового суффикса в slug.
	 *
	 * @param string $subject_key Ключ предмета
	 * @param string $taxonomy Имя таксономии
	 * @param int $term_id ID термина
	 *
	 * @return int Количество заданий
	 */
	private function getExistingTasksCount( string $subject_key, string $taxonomy, int $term_id ): int {
		$query = new \WP_Query( [
			'post_type'      => "{$subject_key}_tasks",
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => false,
			'tax_query'      => [
				[
					'taxonomy' => $taxonomy,
					'field'    => 'term_id',
					'terms'    => $term_id,
				]
			],
		] );

		return (int) $query->found_posts;
	}
}