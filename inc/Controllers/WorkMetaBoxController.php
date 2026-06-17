<?php

declare( strict_types=1 );

namespace Inc\Controllers;

use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\Enums\Nonce;
use Inc\Enums\PostMetaName;
use Inc\Managers\MetaBoxManager;
use Inc\MetaBoxes\Templates\WorkTemplate;
use Inc\Registrars\MetaBoxRegistrar;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Services\PostTypeResolver;
use Inc\Shared\Traits\Authorizer;

/**
 * Class WorkMetaBoxController
 *
 * Регистрирует, рендерит и сохраняет метабокс работы для всех CPT {key}_works.
 *
 * @package Inc\Controllers
 */
class WorkMetaBoxController extends BaseController implements ServiceInterface {

	use Authorizer;

	public function __construct(
		private readonly SubjectRepository $subjects,
		private readonly MetaBoxRegistrar  $registrar,
		private readonly MetaBoxManager    $metaBoxManager,
		private readonly WorkTemplate      $template,
	) {
		parent::__construct();
	}

	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'handleAddMetaBoxes' ) );
		add_action( 'save_post', array( $this, 'handleWorkSave' ) );
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

		echo '<div class="fs-lms-metabox-wrapper fs-lms-work-metabox">';
		$this->template->render( $post );
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

		$this->metaBoxManager->saveFields(
			$post_id,
			PostMetaName::Meta->value,
			is_array( $raw_data ) ? $raw_data : array(),
			$this->template->get_fields()
		);
	}
}
