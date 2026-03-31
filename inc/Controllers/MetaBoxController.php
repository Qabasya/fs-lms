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
class MetaBoxController extends BaseController  implements ServiceInterface {
	/**
	 * Список доступных шаблонов.
	 * @var array
	 */
	private array $templates = [];

	/**
	 * Инициализация шаблонов.
	 */
	/**
	 * Репозиторий для работы с предметами.
	 *
	 * @var SubjectRepository
	 */
	public function __construct( SubjectRepository $subjects, PluginRegistrar $registrar ) {
		parent::__construct();

		// ВАЖНО: Присваиваем зависимости
		$this->subjects  = $subjects;
		$this->registrar = $registrar;

		// Регистрируем доступные шаблоны
		$this->templates['standard_task'] = new StandardTaskTemplate();
	}

	/**
	 * Точка входа в сервис (вызывается из Init.php!).
	 */
	public function register(): void {
		// Регистрация самих боксов
		add_action( 'add_meta_boxes', [ $this, 'init_metaboxes_for_subjects' ] );

		// Сохранение данных (handle_meta_save теперь совпадает с названием метода ниже)
		add_action( 'save_post', [ $this, 'handle_meta_save' ] );
	}

// ============================ ФУНКЦИОНАЛ РЕПОЗИТОРИЯ И РЕГИСТРАТОРА ============================ //

	/**
	 * Логика привязки метабоксов к CPT предметов.
	 */
	public function init_metaboxes_for_subjects(): void {
		$all_subjects = $this->subjects->read_all();

		if ( empty( $all_subjects ) ) {
			return;
		}

		foreach ( $all_subjects as $key => $data ) {
			$task_cpt = "{$key}_tasks";

			// Пока используем стандартный шаблон для всех
			$template = $this->templates['standard_task'];

			// Добавляем в очередь регистратора
			$this->registrar->metabox()->addTemplateBox(
				$template,
				$task_cpt,
				[ $this, 'render_metabox_content' ]
			);
		}

		// Физический вызов add_meta_box через менеджер
		$this->registrar->metabox()->register();
	}

	/**
	 * Коллбек для отрисовки (вызывается WordPress внутри метабокса).
	 */
	public function render_metabox_content( $post, $callback_args ): void {
		$template = $callback_args['args']['template'];

		wp_nonce_field( 'fs_lms_save_meta', 'fs_lms_meta_nonce' );

		$values = get_post_meta( $post->ID, 'fs_lms_meta', true ) ?: [];

		echo '<div class="fs-lms-metabox-wrapper">';
		$template->render( $post, $values );
		echo '</div>';
	}

	/**
	 * Обработчик сохранения.
	 */
	public function handle_meta_save( $post_id ): void {
		if ( ! isset( $_POST['fs_lms_meta_nonce'] ) ) return;
		if ( ! wp_verify_nonce( $_POST['fs_lms_meta_nonce'], 'fs_lms_save_meta' ) ) return;
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
		if ( ! current_user_can( 'edit_post', $post_id ) ) return;

		$template = $this->templates['standard_task'];
		$fields   = $template->get_fields();

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