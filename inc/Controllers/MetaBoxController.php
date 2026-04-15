<?php

namespace Inc\Controllers;

use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\Enums\Nonce;
use Inc\Enums\TaskTemplate;
use Inc\MetaBoxes\Templates\BaseTemplate;
use Inc\MetaBoxes\Templates\CommonConditionTemplate;
use Inc\MetaBoxes\Templates\FileCodeTaskTemplate;
use Inc\MetaBoxes\Templates\FileTaskTemplate;
use Inc\MetaBoxes\Templates\StandardTaskTemplate;
use Inc\MetaBoxes\Templates\CodeTaskTemplate;
use Inc\MetaBoxes\Templates\ThreeInOneTemplate;
use Inc\MetaBoxes\Templates\TwoFileCodeTaskTemplate;
use Inc\Registrars\MetaBoxRegistrar;
use Inc\Repositories\SubjectRepository;
use Inc\Repositories\MetaBoxRepository;
use Inc\DTO\TaskMetaDTO;

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
	private array $templates = [];

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
	 */
	public function register(): void {
		add_action( 'add_meta_boxes', [ $this, 'handleAddMetaBoxes' ] );
		add_action( 'save_post', [ $this, 'handleMetaSave' ] );
		add_filter( 'fs_lms_get_templates', [ $this, 'getTemplatesList' ] );
	}

	/**
	 * Колбек хука add_meta_boxes.
	 * Вынесен в именованный метод, чтобы хук можно было снять через remove_action.
	 */
	public function handleAddMetaBoxes(): void {
		$this->ensureTemplatesLoaded();

		$all_subjects = $this->subjects->readAll();
		if ( empty( $all_subjects ) ) {
			return;
		}

		$task_post_types = array_map(
			static fn( $subject ) => "{$subject->key}_tasks",
			$all_subjects
		);

		$this->registrar->add(
			'fs_lms_task_metabox',
			'Данные задания',
			[ $this, 'renderMetaboxContent' ],
			$task_post_types
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
	 */
	private function ensureTemplatesLoaded(): void {
		if ( ! empty( $this->templates ) ) {
			return;
		}

		$builtin = [
			new CodeTaskTemplate(),
			new FileCodeTaskTemplate(),
			new FileTaskTemplate(),
			new StandardTaskTemplate(),
			new TwoFileCodeTaskTemplate(),
			new ThreeInOneTemplate(),
			new CommonConditionTemplate(),
		];

		/** @var BaseTemplate[] $candidates */
		$candidates = apply_filters( 'fs_lms_register_templates', $builtin );

		foreach ( $candidates as $template ) {
			if ( $template instanceof BaseTemplate ) {
				$this->templates[ $template->get_id() ] = $template;
			} elseif ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'FS LMS: Invalid template (not a BaseTemplate): ' . get_class( $template ) );
			}
		}

		if ( empty( $this->templates ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'FS LMS: No templates were registered!' );
		}
	}

	/**
	 * Резолвит объект шаблона по ID с фолбеком на дефолтный.
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
	 */
	public function renderMetaboxContent( \WP_Post $post ): void {
		$this->ensureTemplatesLoaded();

		$template = $this->resolveTemplate( $this->getTemplateId( $post ) );

		if ( ! $template ) {
			echo '<p>Ошибка: шаблон не найден.</p>';

			return;
		}

		wp_nonce_field( Nonce::SaveMeta->value, 'fs_lms_meta_nonce' );

		echo '<div class="fs-lms-metabox-wrapper">';
		$template->render( $post );
		echo '</div>';
	}

	/**
	 * Обработка сохранения мета-данных поста.
	 *
	 * @param int $post_id ID сохраняемого поста
	 */
	public function handleMetaSave( int $post_id ): void {
		// Пропускаем автосохранение
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		$post = get_post( $post_id );

		if ( ! $post || ! str_ends_with( $post->post_type, '_tasks' ) ) {
			return;
		}

		$nonce = $_POST['fs_lms_meta_nonce'] ?? '';
		if ( ! wp_verify_nonce( $nonce, Nonce::SaveMeta->value ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$this->ensureTemplatesLoaded();

		$template = $this->resolveTemplate( $this->getTemplateId( $post ) );

		if ( ! $template ) {
			return;
		}

		$fields    = $template->get_fields();
		$raw_data  = wp_unslash( $_POST['fs_lms_meta'] ?? [] );
		$sanitized = [];

		foreach ( $fields as $id => $config ) {
			if ( isset( $raw_data[ $id ], $config['object'] ) ) {
				$sanitized[ $id ] = $config['object']->sanitize( $raw_data[ $id ] );
			}
		}

		update_post_meta( $post_id, 'fs_lms_meta', $sanitized );
	}

	/**
	 * Возвращает список всех зарегистрированных шаблонов в виде DTO.
	 * Используется в фильтре fs_lms_get_templates.
	 *
	 * @return TaskMetaDTO[]
	 */
	public function getTemplatesList(): array {
		$this->ensureTemplatesLoaded();

		return array_values( array_map(
			static fn( BaseTemplate $template ) => new TaskMetaDTO(
				id: $template->get_id(),
				title: $template->get_name(),
				fields: $template->get_fields()
			),
			$this->templates
		) );
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
		$subject_key = str_replace( '_tasks', '', $post->post_type );
		$taxonomy    = "{$subject_key}_task_number";

		$terms = wp_get_post_terms( $post->ID, $taxonomy );

		if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
			$assignment = $this->metaboxes->getAssignment( $subject_key, (string) $terms[0]->slug );
			if ( $assignment ) {
				return $assignment->template_id;
			}
		}

		$saved_meta = get_post_meta( $post->ID, '_fs_lms_template_type', true );
		if ( ! empty( $saved_meta ) ) {
			return (string) $saved_meta;
		}

		return TaskTemplate::STANDARD->value;
	}
}