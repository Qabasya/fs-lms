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

		// Инициализируем доступные шаблоны
		$this->templates['standard_task'] = new StandardTaskTemplate();
	}
	/**
	 * Точка входа в сервис (вызывается из Init.php!).
	 */
	public function register(): void {
		$all_subjects = $this->subjects->read_all();

		if ( empty( $all_subjects ) ) {
			return;
		}

		// 1. Проходим по предметам и регистрируем метабоксы для их CPT
		foreach ( $all_subjects as $key => $data ) {
			$task_cpt = "{$key}_tasks";

			// Пока назначаем стандартный шаблон всем заданиям
			$template = $this->templates['standard_task'];

			$this->registrar->metabox()
			                ->addTemplateBox(
				                $template,
				                $task_cpt,
				                [ $this, 'render_metabox_content' ]
			                );
		}

		// 2. Выполняем физическую регистрацию через менеджер (там висит хук add_meta_boxes)
		$this->registrar->metabox()->register();

		// 3. Регистрируем хук сохранения (он работает отдельно от отрисовки)
		add_action( 'save_post', [ $this, 'handle_meta_save' ] );
	}

// ============================ КОЛЛБЕКИ И ОБРАБОТКА ============================ //

	/**
	 * Отрисовка контента метабокса.
	 * Вызывается WordPress, когда он рендерит страницу редактирования.
	 */
	public function render_metabox_content( $post, $callback_args ): void {
		$template = $callback_args['args']['template'];

		wp_nonce_field( 'fs_lms_save_meta', 'fs_lms_meta_nonce' );

		// Получаем текущие данные из мета-полей поста
		$values = get_post_meta( $post->ID, 'fs_lms_meta', true ) ?: [];

		echo '<div class="fs-lms-metabox-wrapper">';
		$template->render( $post, $values );
		echo '</div>';
	}

	/**
	 * Обработка сохранения данных.
	 */
	public function handle_meta_save( $post_id ): void {
		// Проверки безопасности
		if ( ! isset( $_POST['fs_lms_meta_nonce'] ) ) return;
		if ( ! wp_verify_nonce( $_POST['fs_lms_meta_nonce'], 'fs_lms_save_meta' ) ) return;
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
		if ( ! current_user_can( 'edit_post', $post_id ) ) return;

		// Берем поля из шаблона для валидации
		$template = $this->templates['standard_task'];
		$fields   = $template->get_fields();

		$raw_data       = $_POST['fs_lms_meta'] ?? [];
		$sanitized_data = [];

		foreach ( $fields as $id => $config ) {
			if ( isset( $raw_data[ $id ] ) ) {
				// Чистим данные через объект поля
				$sanitized_data[ $id ] = $config['object']->sanitize( $raw_data[ $id ] );
			}
		}

		// Сохраняем в базу через update_post_meta
		update_post_meta( $post_id, 'fs_lms_meta', $sanitized_data );
	}
}