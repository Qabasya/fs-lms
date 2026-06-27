<?php

declare( strict_types=1 );

namespace Inc\Controllers\Course;

use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Services\Course\CourseBuilderService;
use Inc\Services\Subject\PostTypeResolver;

/**
 * Class CourseMetaBoxController
 *
 * Рендерит конструктор курса непосредственно на post.php через edit_form_after_title
 * (без обёртки .postbox). Также убирает лишние нативные WP-элементы на экране курса.
 *
 * @package Inc\Controllers
 */
class CourseMetaBoxController extends BaseController implements ServiceInterface {

	public function __construct(
		private readonly SubjectRepository    $subjects,
		private readonly CourseBuilderService $builder,
	) {
		parent::__construct();
	}

	public function register(): void {
		add_action( 'edit_form_after_title', array( $this, 'renderCourseBuilder' ) );
		add_action( 'add_meta_boxes', array( $this, 'tidyCourseScreen' ), 100 );
		add_action( 'admin_head', array( $this, 'hideTitleOnCourseScreen' ) );
		add_action( 'transition_post_status', array( $this, 'onCoursePublished' ), 10, 3 );
	}

	public function renderCourseBuilder( \WP_Post $post ): void {
		if ( ! PostTypeResolver::isCoursePostType( $post->post_type ) ) {
			return;
		}
		$subject = PostTypeResolver::subjectFromCoursePostType( $post->post_type );
		echo '<div id="fs-lms-course-builder" class="fs-lms-cb-wrap"'
			. ' data-course-id="' . esc_attr( (string) $post->ID ) . '"'
			. ' data-subject="' . esc_attr( $subject ) . '"'
			. '></div>';
	}

	public function tidyCourseScreen( string $post_type ): void {
		if ( ! PostTypeResolver::isCoursePostType( $post_type ) ) {
			return;
		}
		remove_meta_box( 'pageparentdiv', $post_type, 'side' );
		remove_meta_box( 'postimagediv', $post_type, 'side' );
		remove_meta_box( 'authordiv', $post_type, 'normal' );
	}

	public function hideTitleOnCourseScreen(): void {
		$screen = get_current_screen();
		if ( null === $screen || ! PostTypeResolver::isCoursePostType( $screen->post_type ?? '' ) ) {
			return;
		}
		echo '<style>
			#titlediv, #submitdiv, #authordiv, #minor-publishing,
			#normal-sortables, #postbox-container-1,
			h1.wp-heading-inline, .page-title-action, .wp-header-end { display: none !important; }
			#post-body.columns-2 { margin-right: 0 !important; }
			#postbox-container-2 { width: 100% !important; }
			#poststuff { padding-top: 0 !important; }
		</style>';
	}

	public function onCoursePublished( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( 'publish' !== $new_status || $old_status === $new_status ) {
			return;
		}
		if ( ! PostTypeResolver::isCoursePostType( $post->post_type ) ) {
			return;
		}
		$this->builder->publishCourseLessons( $post->ID );
	}
}
