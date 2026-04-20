<?php

namespace Inc\Controllers;

use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\DTO\TaskMetaDTO;
use Inc\Enums\Nonce;
use Inc\Enums\TaskTemplate;
use Inc\MetaBoxes\Templates\BaseTemplate;
use Inc\MetaBoxes\Templates\CodeTaskTemplate;
use Inc\MetaBoxes\Templates\CommonConditionTemplate;
use Inc\MetaBoxes\Templates\FileCodeTaskTemplate;
use Inc\MetaBoxes\Templates\FileTaskTemplate;
use Inc\MetaBoxes\Templates\StandardTaskTemplate;
use Inc\MetaBoxes\Templates\ThreeInOneTemplate;
use Inc\MetaBoxes\Templates\TwoFileCodeTaskTemplate;
use Inc\Registrars\MetaBoxRegistrar;
use Inc\Repositories\MetaBoxRepository;
use Inc\Repositories\SubjectRepository;

/**
 * Class MetaBoxController
 *
 * Контроллер управления метабоксами заданий.
 * Отвечает за динамическую регистрацию шаблонов метабоксов,
 * их регистрацию для каждого предмета и обработку сохранения данных.
 *
 * @package Inc\Controllers
 * @implements ServiceInterface
 */
class MetaBoxController extends BaseController implements ServiceInterface {
	/** @var array<string, BaseTemplate> */
	private array $templates = array();

	/**
	 * Конструктор.
	 *
	 * @param SubjectRepository $subjects  Репозиторий предметов
	 * @param MetaBoxRepository $metaboxes Репозиторий привязок метабоксов
	 * @param MetaBoxRegistrar  $registrar Регистратор метабоксов
	 */
	public function __construct(
		private readonly SubjectRepository $subjects,
		private readonly MetaBoxRepository $metaboxes,
		private readonly MetaBoxRegistrar $registrar
	) {
		parent::__construct();
	}

	// ============================ РЕГИСТРАЦИЯ ============================ //

	/**
	 * Точка входа в сервис (вызывается из Init.php).
	 *
	 * @return void
	 */
	public function register(): void {
		// Регистрация метабоксов на хуке add_meta_boxes
		add_action( 'add_meta_boxes', array( $this, 'handleAddMetaBoxes' ) );

		// Обработка сохранения мета-данных поста
		add_action( 'save_post', array( $this, 'handleMetaSave' ) );

		// Фильтр для получения списка шаблонов
		add_filter( 'fs_lms_get_templates', array( $this, 'getTemplatesList' ) );
	}

	/**
	 * Коллбек хука add_meta_boxes.
	 * Вынесен в именованный метод, чтобы хук можно было снять через remove_action.
	 *
	 * @return void
	 */
	public function handleAddMetaBoxes(): void {
		// Ленивая загрузка шаблонов
		$this->ensureTemplatesLoaded();

		// Получение всех предметов
		$all_subjects = $this->subjects->readAll();

		if ( empty( $all_subjects ) ) {
			return;
		}

		// Сбор всех CPT заданий для регистрации метабокса
		$task_post_types = array_map(
			static fn( $subject ) => "{$subject->key}_tasks",
			$all_subjects
		);

		// Регистрация метабокса через регистратор
		$this->registrar->add(
			'fs_lms_task_metabox',             // Уникальный ID метабокса
			'Данные задания',                  // Заголовок метабокса
			array( $this, 'renderMetaboxContent' ), // Коллбек для отрисовки
			$task_post_types                 // Типы постов (все CPT заданий)
		)->register();
	}

	// ============================ ШАБЛОНЫ ============================ //

	/**
	 * Ленивая инициализация шаблонов — единственная точка загрузки.
	 * Повторный вызов безопасен: если шаблоны уже загружены, ничего не происходит.
	 *
	 * Встроенные шаблоны передаются через фильтр fs_lms_register_templates,
	 * что позволяет внешнему коду добавлять свои шаблоны:
	 *
	 *   add_filter('fs_lms_register_templates', function(array $templates): array {
	 *       $templates[] = new MyCustomTemplate();
	 *       return $templates;
	 *   });
	 *
	 * @return void
	 */
	private function ensureTemplatesLoaded(): void {
		// Если шаблоны уже загружены — выходим
		if ( ! empty( $this->templates ) ) {
			return;
		}

		// Встроенные шаблоны
		$builtin = array(
			new CodeTaskTemplate(),
			new FileCodeTaskTemplate(),
			new FileTaskTemplate(),
			new StandardTaskTemplate(),
			new TwoFileCodeTaskTemplate(),
			new ThreeInOneTemplate(),
			new CommonConditionTemplate(),
		);

		// Применяем фильтр для возможности добавления кастомных шаблонов
		/** @var BaseTemplate[] $candidates */
		$candidates = apply_filters( 'fs_lms_register_templates', $builtin );

		// Регистрация каждого шаблона (только если это экземпляр BaseTemplate)
		foreach ( $candidates as $template ) {
			if ( $template instanceof BaseTemplate ) {
				$this->templates[ $template->get_id() ] = $template;
			} elseif ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'FS LMS: Invalid template (not a BaseTemplate): ' . get_class( $template ) );
			}
		}

		// Логируем, если шаблоны не загрузились
		if ( empty( $this->templates ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'FS LMS: No templates were registered!' );
		}
	}

	/**
	 * Резолвит объект шаблона по ID с фолбеком на дефолтный.
	 *
	 * @param string $template_id ID шаблона
	 *
	 * @return BaseTemplate|null Объект шаблона или null
	 */
	private function resolveTemplate( string $template_id ): ?BaseTemplate {
		return $this->templates[ $template_id ]
				?? $this->templates[ TaskTemplate::STANDARD->value ]
					?? ( ! empty( $this->templates ) ? array_values( $this->templates )[0] : null );
	}

	// ============================ КОЛЛБЕКИ ============================ //

	/**
	 * Отрисовка контента метабокса.
	 *
	 * @param \WP_Post $post Текущий пост
	 *
	 * @return void
	 */
	public function renderMetaboxContent( \WP_Post $post ): void {
		// Ленивая загрузка шаблонов
		$this->ensureTemplatesLoaded();

		// Определение шаблона для текущего поста
		$template = $this->resolveTemplate( $this->getTemplateId( $post ) );

		if ( ! $template ) {
			echo '<p>Ошибка: шаблон не найден.</p>';

			return;
		}

		// Добавление nonce-поля для безопасности
		wp_nonce_field( Nonce::SaveMeta->value, 'fs_lms_meta_nonce' );

		// Рендеринг содержимого метабокса
		echo '<div class="fs-lms-metabox-wrapper">';
		$template->render( $post );
		echo '</div>';
	}

	/**
	 * Обработка сохранения мета-данных поста.
	 *
	 * @param int $post_id ID сохраняемого поста
	 *
	 * @return void
	 */
	public function handleMetaSave( int $post_id ): void {
		// Пропускаем автосохранение
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		$post = get_post( $post_id );

		// Проверяем, что пост существует и является заданием (оканчивается на "_tasks")
		if ( ! $post || ! str_ends_with( $post->post_type, '_tasks' ) ) {
			return;
		}

		// Проверка nonce
		$nonce = $_POST['fs_lms_meta_nonce'] ?? '';
		if ( ! wp_verify_nonce( $nonce, Nonce::SaveMeta->value ) ) {
			return;
		}

		// Проверка прав текущего пользователя
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Ленивая загрузка шаблонов
		$this->ensureTemplatesLoaded();

		// Определение шаблона для текущего поста
		$template = $this->resolveTemplate( $this->getTemplateId( $post ) );

		if ( ! $template ) {
			return;
		}

		// Получение полей шаблона
		$fields = $template->get_fields();

		// Получение сырых данных из POST
		$raw_data = wp_unslash( $_POST['fs_lms_meta'] ?? array() );

		// Санитизация данных
		$sanitized = array();
		foreach ( $fields as $id => $config ) {
			if ( isset( $raw_data[ $id ], $config['object'] ) ) {
				$sanitized[ $id ] = $config['object']->sanitize( $raw_data[ $id ] );
			}
		}

		// Сохранение мета-данных
		update_post_meta( $post_id, 'fs_lms_meta', $sanitized );
	}

	/**
	 * Возвращает список всех зарегистрированных шаблонов в виде DTO.
	 * Используется в фильтре fs_lms_get_templates.
	 *
	 * @return TaskMetaDTO[]
	 */
	public function getTemplatesList(): array {
		// Ленивая загрузка шаблонов
		$this->ensureTemplatesLoaded();

		return array_values(
			array_map(
				static fn( BaseTemplate $template ) => new TaskMetaDTO(
					id    : $template->get_id(),
					title : $template->get_name(),
					fields: $template->get_fields()
				),
				$this->templates
			)
		);
	}

	// ============================ ВСПОМОГАТЕЛЬНЫЕ МЕТОДЫ ============================ //

	/**
	 * Определяет ID шаблона для конкретного поста.
	 *
	 * Приоритет (высший → низший):
	 * 1. Глобальные настройки предмета (MetaBoxRepository)
	 * 2. Мета-поле конкретного поста (_fs_lms_template_type) — обратная совместимость
	 * 3. Дефолтный шаблон (standard_task)
	 *
	 * @param \WP_Post $post Объект поста
	 *
	 * @return string ID выбранного шаблона
	 */
	private function getTemplateId( \WP_Post $post ): string {
		// Извлечение ключа предмета из post_type (например, "math_tasks" → "math")
		$subject_key = str_replace( '_tasks', '', $post->post_type );

		// Имя таксономии для номеров заданий
		$taxonomy = "{$subject_key}_task_number";

		// Получение терминов (номеров заданий) для поста
		$terms = wp_get_post_terms( $post->ID, $taxonomy );

		// ПРИОРИТЕТ 1: Глобальные настройки предмета (из MetaBoxRepository)
		if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
			$assignment = $this->metaboxes->getAssignment( $subject_key, (string) $terms[0]->slug );
			if ( $assignment ) {
				return $assignment->template_id;
			}
		}

		// ПРИОРИТЕТ 2: Мета-поле конкретного поста (обратная совместимость)
		$saved_meta = get_post_meta( $post->ID, '_fs_lms_template_type', true );
		if ( ! empty( $saved_meta ) ) {
			return (string) $saved_meta;
		}

		// ПРИОРИТЕТ 3: Стандартный шаблон по умолчанию
		return TaskTemplate::STANDARD->value;
	}
}
