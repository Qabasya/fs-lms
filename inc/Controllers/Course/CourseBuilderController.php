<?php

declare( strict_types=1 );

namespace Inc\Controllers\Course;

use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\Enums\Access\Capability;
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
			Capability::AuthorLmsCourses->value,
			self::PAGE_SLUG,
			array( $this, 'renderPage' )
		);
	}

	/**
	 * Рендерит контейнер-маунт приложения.
	 */
	public function renderPage(): void {
		$course_id = absint( wp_unslash( $_GET['course'] ?? 0 ) );
		$subject   = sanitize_key( wp_unslash( $_GET['subject'] ?? '' ) );
		$post      = $course_id > 0 ? get_post( $course_id ) : null;

		$this->render( 'admin/course-builder', array(
			'course_id'    => $course_id,
			'subject'      => $subject,
			'post'         => $post,
			'is_published' => $post && 'publish' === get_post_status( $post ),
			'preview_url'  => $post ? get_preview_post_link( $post ) : '',
			'trash_url'    => $post ? get_delete_post_link( $post->ID, '', true ) : '',
			'author_id'    => $post ? (int) $post->post_author : 0,
		) );
	}
}
