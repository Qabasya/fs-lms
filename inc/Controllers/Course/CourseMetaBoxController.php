<?php

declare( strict_types=1 );

namespace Inc\Controllers\Course;

use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Services\Course\CourseBuilderService;
use Inc\Services\Subject\PostTypeResolver;
use Inc\Shared\Traits\TemplateRenderer;

/**
 * Class CourseMetaBoxController
 *
 * Рендерит конструктор курса непосредственно на post.php через edit_form_after_title
 * (без обёртки .postbox). Также убирает лишние нативные WP-элементы на экране курса.
 *
 * @package Inc\Controllers
 */
class CourseMetaBoxController extends BaseController implements ServiceInterface {

	use TemplateRenderer;

	public function __construct(
		private readonly SubjectRepository    $subjects,
		private readonly CourseBuilderService $builder,
	) {
		parent::__construct();
	}

	public function register(): void {
		add_action( 'edit_form_after_title', array( $this, 'renderCourseBuilder' ) );
		add_action( 'add_meta_boxes', array( $this, 'tidyCourseScreen' ), 100 );
		add_filter( 'admin_body_class', array( $this, 'markCourseScreen' ) );
		add_action( 'transition_post_status', array( $this, 'onCoursePublished' ), 10, 3 );
	}

	public function renderCourseBuilder( \WP_Post $post ): void {
		if ( ! PostTypeResolver::isCoursePostType( $post->post_type ) ) {
			return;
		}
		$this->render( 'admin/metaboxes/course-builder-mount', array(
			'course_id' => $post->ID,
			'subject'   => PostTypeResolver::subjectFromCoursePostType( $post->post_type ),
		) );
	}

	public function tidyCourseScreen( string $post_type ): void {
		if ( ! PostTypeResolver::isCoursePostType( $post_type ) ) {
			return;
		}
		remove_meta_box( 'pageparentdiv', $post_type, 'side' );
		remove_meta_box( 'postimagediv', $post_type, 'side' );
		remove_meta_box( 'authordiv', $post_type, 'normal' );
	}

	/**
	 * Помечает экран редактирования курса классом на body.
	 *
	 * Конструктор занимает весь экран, поэтому хром WP (заголовок, сайдбар,
	 * стандартные метабоксы) прячется стилями по этому классу — см.
	 * `_course-builder.scss`. Инлайновый `<style>` в админ-хеде для этого
	 * не используется (запрет на inline-стили).
	 *
	 * @param string $classes Текущие классы body
	 */
	public function markCourseScreen( string $classes ): string {
		$screen = get_current_screen();
		if ( null === $screen || 'post' !== $screen->base || ! PostTypeResolver::isCoursePostType( $screen->post_type ?? '' ) ) {
			return $classes;
		}

		return $classes . ' fs-lms-course-screen';
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
