<?php

namespace Inc\Controllers;

use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\MetaBoxes\Templates\StandardTaskTemplate;
use Inc\Repositories\SubjectRepository;
use Inc\Registrars\PluginRegistrar;

/**
 * Контроллер управления метабоксами заданий.
 */
class MetaBoxController extends BaseController implements ServiceInterface {
	/**
	 * Список доступных шаблонов метабоксов.
	 */
	private array $templates = [];

	/**
	 * Репозиторий предметов.
	 */
	protected SubjectRepository $subjects;

	/**
	 * Композитный регистратор.
	 */
	private PluginRegistrar $registrar;

	/**
	 * Конструктор.
	 */
	public function __construct( SubjectRepository $subjects, PluginRegistrar $registrar ) {
		parent::__construct();

		$this->subjects  = $subjects;
		$this->registrar = $registrar;

		$this->load_templates_dynamically();
	}

	/**
	 * Сканирует папку Templates и инициализирует классы.
	 */
	private function load_templates_dynamically(): void {
		$template_path = $this->path( 'inc/MetaBoxes/Templates/' );

		if ( ! is_dir( $template_path ) ) {
			return;
		}

		$files = scandir( $template_path );

		foreach ( $files as $file ) {
			// Пропускаем базу и не-php файлы
			if ( in_array( $file, [ '.', '..', 'BaseTemplate.php' ] ) || strpos( $file, '.php' ) === false ) {
				continue;
			}

			$class_name = "\\Inc\\MetaBoxes\\Templates\\" . str_replace( '.php', '', $file );

			if ( class_exists( $class_name ) ) {
				$template_obj = new $class_name();
				// Индексируем по ID шаблона (например, 'standard_task')
				$this->templates[ $template_obj->get_id() ] = $template_obj;
			}
		}
	}

	/**
	 * Точка входа в сервис (вызывается из Init.php!).
	 */
	public function register(): void {
		$all_subjects = $this->subjects->read_all();
		if ( empty( $all_subjects ) ) return;

		$placeholder_template = $this->templates['standard_task'] ?? reset($this->templates);

		if ( ! $placeholder_template ) {
			// Если список шаблонов вообще пуст (директория не прочиталась),
			error_log('FS LMS: No templates found in Inc/MetaBoxes/Templates/');
			return;
		}

		foreach ( $all_subjects as $key => $data ) {
			$task_cpt = "{$key}_tasks";

			$this->registrar->metabox()
			                ->addTemplateBox(
				                $placeholder_template,
				                $task_cpt,
				                [ $this, 'render_metabox_content' ]
			                );
		}

		$this->registrar->metabox()->register();
		add_action( 'save_post', [ $this, 'handle_meta_save' ] );
	}

// ============================ КОЛЛБЕКИ И ОБРАБОТКА ============================ //

	/**
	 * Отрисовка контента метабокса.
	 */
	public function render_metabox_content( $post, $callback_args ): void {
		// 1. Достаем тип, который мы выбрали в модалке при создании
		$template_id = get_post_meta( $post->ID, '_fs_lms_template_type', true ) ?: 'standard_task';

		// 2. Ищем объект этого шаблона в нашем динамическом списке
		$template = $this->templates[ $template_id ] ?? $this->templates['standard_task'];

		wp_nonce_field( 'fs_lms_save_meta', 'fs_lms_meta_nonce' );

		// 3. Получаем текущие данные из единого массива
		$values = get_post_meta( $post->ID, 'fs_lms_meta', true ) ?: [];

		echo '<div class="fs-lms-metabox-wrapper">';

		$template->render( $post, $values );

		echo '</div>';
	}

	/**
	 * Обработка сохранения.
	 */
	public function handle_meta_save( $post_id ): void {
		if ( ! isset( $_POST['fs_lms_meta_nonce'] ) || ! wp_verify_nonce( $_POST['fs_lms_meta_nonce'], 'fs_lms_save_meta' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Определяем шаблон, чтобы знать, какие поля чистить
		$template_id = get_post_meta( $post_id, '_fs_lms_template_type', true ) ?: 'standard_task';
		$template    = $this->templates[ $template_id ] ?? null;

		if ( $template ) {
			$fields         = $template->get_fields();
			$raw_data       = $_POST['fs_lms_meta'] ?? [];
			$sanitized_data = [];

			foreach ( $fields as $id => $config ) {
				if ( isset( $raw_data[ $id ] ) ) {
					$sanitized_data[ $id ] = $config['object']->sanitize( $raw_data[ $id ] );
				}
			}
			update_post_meta( $post_id, 'fs_lms_meta', $sanitized_data );
		}
	}
}