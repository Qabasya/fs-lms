<?php

declare( strict_types=1 );

namespace Inc\Controllers\Course;

use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\Registrars\MetaBoxRegistrar;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Services\Subject\PostTypeResolver;

/**
 * Class CourseMetaBoxController
 *
 * Регистрирует, рендерит и сохраняет метабокс курса для всех CPT {key}_courses.
 *
 * @package Inc\Controllers
 */
class CourseMetaBoxController extends BaseController implements ServiceInterface {

	public function __construct(
		private readonly SubjectRepository $subjects,
		private readonly MetaBoxRegistrar  $registrar,
	) {
		parent::__construct();
	}

	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'handleAddMetaBoxes' ) );
		add_action( 'add_meta_boxes', array( $this, 'moveAuthorToSidebar' ), 100 );
	}

	public function handleAddMetaBoxes(): void {
		$all_subjects = $this->subjects->readAll();
		if ( empty( $all_subjects ) ) {
			return;
		}

		$course_post_types = array_map(
			static fn( $subject ) => PostTypeResolver::courses( $subject->key ),
			$all_subjects
		);

		$this->registrar->add(
			'fs_lms_course_metabox',
			'Конструктор курса',
			array( $this, 'renderMetaboxContent' ),
			$course_post_types
		)->register();
	}

	public function moveAuthorToSidebar( string $post_type ): void {
		if ( ! PostTypeResolver::isCoursePostType( $post_type ) ) {
			return;
		}
		remove_meta_box( 'authordiv', $post_type, 'normal' );
		add_meta_box( 'fs_lms_course_authordiv', __( 'Автор' ), 'post_author_meta_box', $post_type, 'side', 'core' );
	}

	public function renderMetaboxContent( \WP_Post $post ): void {
		$subject = PostTypeResolver::subjectFromCoursePostType( $post->post_type );
		echo '<div id="fs-lms-course-builder" class="fs-lms-cb-wrap"'
			. ' data-course-id="' . esc_attr( (string) $post->ID ) . '"'
			. ' data-subject="' . esc_attr( $subject ) . '"'
			. '></div>';
	}
}
