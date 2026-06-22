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
	) {
		parent::__construct();
	}

	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'handleAddMetaBoxes' ) );
		add_action( 'add_meta_boxes', array( $this, 'tidyWorkMetaBoxes' ), 100 );
		add_action( 'save_post', array( $this, 'handleWorkSave' ) );
	}

	/**
	 * Прибирает экран работы: убирает «Атрибуты»/«Изображение записи», «Автор» → в сайдбар.
	 * Редактор (описание) и метабокс работы остаются.
	 */
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
			'fs_lms_work_metabox',
			'Данные работы',
			array( $this, 'renderMetaboxContent' ),
			$work_post_types
		)->register();
	}

	public function renderMetaboxContent( \WP_Post $post ): void {
		wp_nonce_field( Nonce::SaveMeta->value, 'fs_lms_meta_nonce' );

		$subject = PostTypeResolver::subjectFromWorkPostType( $post->post_type );
		$work    = $this->works->get( $post->ID );
		$itemIds = null !== $work ? $work->itemIds : array();

		// item_ids → task-шаги для единого степ-редактора (level=work, только задачи).
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

		echo '<div class="fs-lms-metabox-wrapper fs-lms-work-metabox">';
		$this->template->render( $post ); // только «Тип работы»
		echo '<div class="fs-lms-work-builder" '
			. 'data-work-id="' . esc_attr( (string) $post->ID ) . '" '
			. 'data-subject="' . esc_attr( $subject ) . '" '
			. 'data-level="work">';
		echo '<script type="application/json" class="fs-sb-data">' . ( $json ?: '[]' ) . '</script>';
		echo '</div>';
		echo '</div>';
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
