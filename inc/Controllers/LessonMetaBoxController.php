<?php

declare( strict_types=1 );

namespace Inc\Controllers;

use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\Enums\Nonce;
use Inc\Enums\PostMetaName;
use Inc\Managers\MetaBoxManager;
use Inc\MetaBoxes\Templates\LessonTemplate;
use Inc\Registrars\MetaBoxRegistrar;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Services\PostTypeResolver;
use Inc\Shared\Traits\Authorizer;

/**
 * Class LessonMetaBoxController
 *
 * Регистрирует, рендерит и сохраняет метабокс урока для всех CPT {key}_lessons.
 *
 * @package Inc\Controllers
 */
class LessonMetaBoxController extends BaseController implements ServiceInterface {

	use Authorizer;

	public function __construct(
		private readonly SubjectRepository $subjects,
		private readonly MetaBoxRegistrar  $registrar,
		private readonly MetaBoxManager    $metaBoxManager,
		private readonly LessonTemplate    $template,
	) {
		parent::__construct();
	}

	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'handleAddMetaBoxes' ) );
		add_action( 'save_post', array( $this, 'handleLessonSave' ) );
	}

	public function handleAddMetaBoxes(): void {
		$all_subjects = $this->subjects->readAll();
		if ( empty( $all_subjects ) ) {
			return;
		}

		$lesson_post_types = array_map(
			static fn( $subject ) => PostTypeResolver::lessons( $subject->key ),
			$all_subjects
		);

		$this->registrar->add(
			'fs_lms_lesson_metabox',
			'Данные урока',
			array( $this, 'renderMetaboxContent' ),
			$lesson_post_types
		)->register();
	}

	public function renderMetaboxContent( \WP_Post $post ): void {
		wp_nonce_field( Nonce::SaveMeta->value, 'fs_lms_meta_nonce' );

		echo '<div class="fs-lms-metabox-wrapper fs-lms-lesson-metabox">';
		$this->template->render( $post );
		echo '</div>';
	}

	public function handleLessonSave( int $post_id ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post || ! PostTypeResolver::isLessonPostType( $post->post_type ) ) {
			return;
		}

		if ( ! $this->authorizePostSave( Nonce::SaveMeta, $post_id ) ) {
			return;
		}

		$raw_data = wp_unslash( $_POST[ PostMetaName::Meta->value ] ?? array() );

		$this->metaBoxManager->saveFields(
			$post_id,
			PostMetaName::Meta->value,
			is_array( $raw_data ) ? $raw_data : array(),
			$this->template->get_fields()
		);
	}
}
