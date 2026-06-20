<?php

declare( strict_types=1 );

namespace Inc\Controllers;

use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\Enums\Access\Capability;
use Inc\Services\PostTypeResolver;
use Inc\Shared\Traits\TemplateRenderer;

/**
 * Class CourseBuilderController
 *
 * Регистрирует страницу-приложение Stepik-конструктора курса (канон —
 * design_handoff_course_builder/). Скрытая admin-страница `fs_lms_course_builder`,
 * монтирующая JS-приложение (`#fs-lms-course-builder`). Открывается с
 * `?course=<id>` (правка) или `?subject=<key>` (создание нового курса).
 *
 * @package Inc\Controllers
 */
class CourseBuilderController extends BaseController implements ServiceInterface {

	use TemplateRenderer;

	/** Слаг страницы (начинается с fs_ → Enqueue подхватывает ассеты). */
	public const PAGE_SLUG = 'fs_lms_course_builder';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'registerPage' ) );
		add_action( 'load-post.php', array( $this, 'redirectCourseEditToBuilder' ) );
		add_action( 'load-post-new.php', array( $this, 'redirectCourseNewToBuilder' ) );
	}

	public function redirectCourseEditToBuilder(): void {
		$post_id = absint( wp_unslash( $_GET['post'] ?? 0 ) );
		if ( $post_id <= 0 ) {
			return;
		}
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || ! PostTypeResolver::isCoursePostType( $post->post_type ) ) {
			return;
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&course=' . $post_id ) );
		exit;
	}

	public function redirectCourseNewToBuilder(): void {
		$post_type = sanitize_key( wp_unslash( $_GET['post_type'] ?? '' ) );
		if ( ! PostTypeResolver::isCoursePostType( $post_type ) ) {
			return;
		}
		$subject = PostTypeResolver::subjectFromCoursePostType( $post_type );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&subject=' . rawurlencode( $subject ) ) );
		exit;
	}

	/**
	 * Регистрирует скрытую страницу конструктора (не показывается в меню,
	 * доступна по слагу; ссылки ведут из библиотеки «Курсы» — этап интеграции).
	 */
	public function registerPage(): void {
		add_submenu_page(
			'',
			__( 'Конструктор курса', 'fs-lms' ),
			__( 'Конструктор курса', 'fs-lms' ),
			Capability::ManageLMSAssignments->value,
			self::PAGE_SLUG,
			array( $this, 'renderPage' )
		);
	}

	/**
	 * Рендерит контейнер-маунт приложения.
	 */
	public function renderPage(): void {
		$this->render( 'admin/course-builder', array(
			'course_id' => absint( wp_unslash( $_GET['course'] ?? 0 ) ),
			'subject'   => sanitize_key( wp_unslash( $_GET['subject'] ?? '' ) ),
		) );
	}
}
