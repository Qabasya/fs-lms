<?php

namespace Inc\Controllers;

use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\DTO\TaskMetaDTO;
use Inc\Enums\Nonce;
use Inc\Registrars\MetaBoxRegistrar;
use Inc\Repositories\SubjectRepository;
use Inc\Services\TemplateRegistry;
use Inc\Services\TemplateResolver;

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
	/**
	 * Конструктор.
	 */
	public function __construct(
		private readonly SubjectRepository $subjects,
		private readonly MetaBoxRegistrar $registrar,
		private readonly TemplateRegistry $registry,
		private readonly TemplateResolver $resolver
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
	 * Регистрирует метабокс для всех CPT заданий.
	 */
	public function handleAddMetaBoxes(): void {
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
			array( $this, 'renderMetaboxContent' ),
			$task_post_types
		)->register();
	}

	/**
	 * Отрисовка контента метабокса.
	 */
	public function renderMetaboxContent( \WP_Post $post ): void {
		$template_id = $this->resolver->resolveId( $post );
		$template    = $this->registry->get( $template_id );

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
	 * Обработка сохранения данных.
	 */
	public function handleMetaSave( int $post_id ): void {
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! isset( $_POST['fs_lms_meta_nonce'] ) ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post || ! str_ends_with( $post->post_type, '_tasks' ) ) {
			return;
		}

		if ( ! wp_verify_nonce( $_POST['fs_lms_meta_nonce'], Nonce::SaveMeta->value ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$template_id = $this->resolver->resolveId( $post );
		$template    = $this->registry->get( $template_id );

		if ( ! $template ) {
			return;
		}

		$fields    = $template->get_fields();
		$raw_data  = wp_unslash( $_POST['fs_lms_meta'] ?? array() );
		$sanitized = array();

		foreach ( $fields as $id => $config ) {
			if ( isset( $raw_data[ $id ], $config['object'] ) ) {
				$sanitized[ $id ] = $config['object']->sanitize( $raw_data[ $id ] );
			}
		}

		update_post_meta( $post_id, 'fs_lms_meta', $sanitized );
	}

	/**
	 * Возвращает список всех зарегистрированных шаблонов в виде DTO.
	 */
	public function getTemplatesList(): array {
		return array_map(
			static fn( $template ) => new TaskMetaDTO(
				id    : $template->get_id(),
				title : $template->get_name(),
				fields: $template->get_fields()
			),
			array_values( $this->registry->getAll() )
		);
	}
}
