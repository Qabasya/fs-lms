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
	private SubjectRepository $subjects;

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

		$this->loadTemplatesDynamically();
	}

	/**
	 * Загружает все шаблоны из папки Templates.
	 * Используем glob() + строгая проверка класса.
	 */
	private function loadTemplatesDynamically(): void {
		$template_path = $this->path( 'inc/MetaBoxes/Templates/' );

		if ( ! is_dir( $template_path ) ) {
			error_log( 'FS LMS: Templates directory not found: ' . $template_path );

			return;
		}

		// Ищем файлы *Template.php,
		$files = glob( $template_path . '*Template.php' );

		if ( ! $files ) {
			return;
		}

		foreach ( $files as $file ) {
			$basename   = basename( $file, '.php' );
			$class_name = "\\Inc\\MetaBoxes\\Templates\\" . $basename;

			// Пропускаем базовый класс
			if ( $basename === 'BaseTemplate' ) {
				continue;
			}

			if ( class_exists( $class_name ) ) {
				$template = new $class_name();

				// Дополнительная защита: проверяем наличие нужного метода
				if ( method_exists( $template, 'get_id' ) && method_exists( $template, 'render' ) ) {
					$this->templates[ $template->get_id() ] = $template;
				}
			}
		}

		if ( empty( $this->templates ) ) {
			error_log( 'FS LMS: No valid templates found in Inc/MetaBoxes/Templates/' );
		}
	}

	/**
	 * Точка входа в сервис (вызывается из Init.php!).
	 */
	public function register(): void {
		$all_subjects = $this->subjects->read_all();

		if ( empty( $all_subjects ) ) {
			return;
		}

		$default_template = $this->templates['standard_task']
		                    ?? reset( $this->templates );

		if ( ! $default_template ) {
			error_log( 'FS LMS: No default template available for metaboxes' );

			return;
		}

		foreach ( $all_subjects as $key => $data ) {
			$task_cpt = "{$key}_tasks";

			$this->registrar->metabox()
			                ->addTemplateBox(
				                $default_template,
				                $task_cpt,
				                [ $this, 'renderMetaboxContent' ]
			                );
		}

		$this->registrar->metabox()->register();
		add_action( 'save_post', [ $this, 'handleMetaSave' ] );
	}

// ============================ КОЛЛБЕКИ И ОБРАБОТКА ============================ //

	/**
	 * Отрисовка контента метабокса.
	 */
	public function renderMetaboxContent( $post, $callback_args ): void {
		// 1. Достаем тип, который мы выбрали в модалке при создании
		$template_id = get_post_meta( $post->ID, '_fs_lms_template_type', true ) ?: 'standard_task';

		// 2. Ищем объект этого шаблона в нашем динамическом списке
		$template = $this->templates[ $template_id ] ?? $this->templates['standard_task']
		                                                ?? reset( $this->templates );

		if ( ! $template ) {
			echo '<p>Шаблон не найден.</p>';

			return;
		}

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
	public function handleMetaSave( $post_id ): void {
		if ( ! isset( $_POST['fs_lms_meta_nonce'] )
		     || ! wp_verify_nonce( $_POST['fs_lms_meta_nonce'], 'fs_lms_save_meta' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Определяем шаблон, чтобы знать, какие поля чистить
		$template_id = get_post_meta( $post_id, '_fs_lms_template_type', true ) ?: 'standard_task';
		$template    = $this->templates[ $template_id ] ?? null;

		if ( ! $template || ! method_exists( $template, 'get_fields' ) ) {
			return;
		}

		$fields    = $template->get_fields();
		$raw_data  = $_POST['fs_lms_meta'] ?? [];
		$sanitized = [];

		foreach ( $fields as $id => $config ) {
			if ( isset( $raw_data[ $id ] ) && isset( $config['object'] ) ) {
				$sanitized[ $id ] = $config['object']->sanitize( $raw_data[ $id ] );
			}
		}

		update_post_meta( $post_id, 'fs_lms_meta', $sanitized );
	}
}