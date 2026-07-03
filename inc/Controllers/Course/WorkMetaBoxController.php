<?php

declare( strict_types=1 );

namespace Inc\Controllers\Course;

use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\Enums\Wp\Nonce;
use Inc\Enums\Wp\PostMetaName;
use Inc\Managers\Course\WorkManager;
use Inc\Managers\Wp\MetaBoxManager;
use Inc\MetaBoxes\Templates\WorkTemplate;
use Inc\Registrars\MetaBoxRegistrar;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Services\Subject\PostTypeResolver;
use Inc\Services\Task\TaskPublishGuard;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\TidiesCoreMetaBoxes;

/**
 * Class WorkMetaBoxController
 *
 * Регистрирует, рендерит и сохраняет метабокс работы для всех CPT {key}_works.
 *
 * @package Inc\Controllers
 */
class WorkMetaBoxController extends BaseController implements ServiceInterface {

	use Authorizer;
	use TidiesCoreMetaBoxes;

	public function __construct(
		private readonly SubjectRepository $subjects,
		private readonly MetaBoxRegistrar  $registrar,
		private readonly MetaBoxManager    $metaBoxManager,
		private readonly WorkTemplate      $template,
		private readonly WorkManager       $works,
		private readonly TaskPublishGuard  $guard,
	) {
		parent::__construct();
	}

	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'handleAddMetaBoxes' ) );
		add_action( 'add_meta_boxes', array( $this, 'tidyWorkMetaBoxes' ), 100 );
		add_action( 'save_post', array( $this, 'handleWorkSave' ) );
		add_action( 'transition_post_status', array( $this, 'handleWorkPublish' ), 10, 3 );
		// #10: не даём опубликовать работу без названия (откат в draft + notice).
		add_filter( 'wp_insert_post_data', array( $this, 'validateWorkTitle' ), 10, 2 );
		add_action( 'admin_notices', array( $this, 'showPublishError' ) );
	}

	/**
	 * Блокирует публикацию работы без названия (`wp_insert_post_data`).
	 *
	 * @param array<string, mixed> $data
	 * @param array<string, mixed> $postarr
	 *
	 * @return array<string, mixed>
	 */
	public function validateWorkTitle( array $data, array $postarr ): array {
		if ( ! PostTypeResolver::isWorkPostType( $data['post_type'] ?? '' ) ) {
			return $data;
		}

		return $this->guard->enforce(
			$data,
			'fs_lms_work_publish_error_',
			'Укажите название работы.',
			static fn(): ?string => null
		);
	}

	/** Выводит отложенную ошибку публикации работы на экране редактирования. */
	public function showPublishError(): void {
		$screen = get_current_screen();
		if ( ! $screen || ! PostTypeResolver::isWorkPostType( $screen->post_type ) ) {
			return;
		}
		$this->guard->renderDeferredError( 'fs_lms_work_publish_error_', __( 'Невозможно опубликовать работу', 'fs-lms' ) );
	}

	public function tidyWorkMetaBoxes( string $post_type ): void {
		if ( PostTypeResolver::isWorkPostType( $post_type ) ) {
			$this->tidyCoreMetaBoxes( $post_type );
		}
	}

	public function handleAddMetaBoxes(): void {
		$all_subjects = $this->subjects->readAll();
		if ( empty( $all_subjects ) ) {
			return;
		}

		$work_post_types = array_map(
			static fn( $subject ) => PostTypeResolver::works( $subject->key ),
			$all_subjects
		);

		$this->registrar->add(
			'fs_lms_work_settings',
			'Настройки работы',
			array( $this, 'renderSettingsContent' ),
			$work_post_types
		)->register();

		$this->registrar->add(
			'fs_lms_work_builder',
			'Конструктор работы',
			array( $this, 'renderBuilderContent' ),
			$work_post_types
		)->register();
	}

	public function renderSettingsContent( \WP_Post $post ): void {
		wp_nonce_field( Nonce::SaveMeta->value, 'fs_lms_meta_nonce' );
		echo '<div class="fs-lms-work-settings">';
		$this->template->render( $post );
		echo '</div>';
	}

	public function renderBuilderContent( \WP_Post $post ): void {
		$subject = PostTypeResolver::subjectFromWorkPostType( $post->post_type );
		$work    = $this->works->get( $post->ID );
		$itemIds = null !== $work ? $work->itemIds : array();

		$steps = array();
		foreach ( $itemIds as $id ) {
			$id = (int) $id;
			if ( $id <= 0 ) {
				continue;
			}
			$steps[] = array(
				'key'     => 'item_' . $id,
				'type'    => 'task',
				'payload' => array( 'ref' => $id ),
				'_title'  => get_the_title( $id ),
			);
		}
		$json = wp_json_encode( $steps, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );

		echo '<div class="fs-sb-wrap">';
		echo '<div class="fs-lms-work-builder" '
			. 'data-work-id="' . esc_attr( (string) $post->ID ) . '" '
			. 'data-subject="' . esc_attr( $subject ) . '" '
			. 'data-level="work">';
		echo '<script type="application/json" class="fs-sb-data">' . ( $json ?: '[]' ) . '</script>';
		echo '</div>';
		echo '</div>';
	}

	public function handleWorkPublish( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( 'publish' !== $new_status || $old_status === $new_status ) {
			return;
		}
		if ( ! PostTypeResolver::isWorkPostType( $post->post_type ) ) {
			return;
		}
		$work = $this->works->get( $post->ID );
		if ( null === $work || empty( $work->itemIds ) ) {
			return;
		}
		foreach ( $work->itemIds as $item_id ) {
			$item = get_post( $item_id );
			if ( $item instanceof \WP_Post && in_array( $item->post_status, array( 'draft', 'auto-draft', 'pending' ), true ) ) {
				wp_publish_post( $item_id );
			}
		}
	}

	public function handleWorkSave( int $post_id ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post || ! PostTypeResolver::isWorkPostType( $post->post_type ) ) {
			return;
		}

		if ( ! $this->authorizePostSave( Nonce::SaveMeta, $post_id ) ) {
			return;
		}

		$raw_data = wp_unslash( $_POST[ PostMetaName::Meta->value ] ?? array() );

		// Мерж: сохраняем только work_type, item_ids (степ-лист, AJAX) не затираем.
		$this->metaBoxManager->saveFieldsMerge(
			$post_id,
			PostMetaName::Meta->value,
			is_array( $raw_data ) ? $raw_data : array(),
			$this->template->get_fields()
		);
	}
}
