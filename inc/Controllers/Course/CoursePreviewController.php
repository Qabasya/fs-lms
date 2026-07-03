<?php

declare( strict_types=1 );

namespace Inc\Controllers\Course;

use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\Enums\Access\Capability;
use Inc\Enums\Wp\PageRoutes;
use Inc\Managers\Course\CourseManager;
use Inc\Services\Course\CoursePreviewAccessGuard;
use Inc\Services\Course\CoursePreviewService;

/**
 * Class CoursePreviewController
 *
 * Preview-плеер курса (Фаза 5, D3/D4): маршрут `/course-preview/?course=&lesson=&step=`.
 * В отличие от `LessonPlayerController` (`?gid=&gl=`, гейт по членству в группе), доступ
 * здесь целиком свой (`CoursePreviewAccessGuard`) и не зависит от статуса поста курса —
 * преподаватель должен видеть предпросмотр черновика ровно так же, как опубликованного.
 *
 * @package Inc\Controllers\Course
 */
class CoursePreviewController extends BaseController implements ServiceInterface {

	public function __construct(
		private readonly CoursePreviewAccessGuard $access,
		private readonly CoursePreviewService     $preview,
		private readonly CourseManager            $courses,
	) {
		parent::__construct();
	}

	public function register(): void {
		add_filter( 'template_include', array( $this, 'loadTemplate' ) );
	}

	public function loadTemplate( string $template ): string {
		if ( ! PageRoutes::CoursePreview->isCurrent() || ! isset( $_GET['course'] ) ) {
			return $template;
		}

		$userId = get_current_user_id();
		if ( ! $userId ) {
			wp_redirect( wp_login_url( $this->currentDeepLink() ) );
			exit;
		}

		$courseId = (int) $_GET['course'];
		if ( ! $this->access->canPreview( $userId, $courseId ) ) {
			// Постороннему не раскрываем наличие курса (404), как в LessonPlayerController.
			return $this->notFound();
		}

		$course = $this->courses->get( $courseId );
		if ( null === $course ) {
			return $this->notFound();
		}

		$lessonId = isset( $_GET['lesson'] ) ? (int) $_GET['lesson'] : ( $course->lessonIds()[0] ?? 0 );
		$view     = $lessonId ? $this->preview->buildView( $courseId, $lessonId ) : null;
		if ( null === $view ) {
			return $this->notFound();
		}

		$view['shell'] = $this->preview->shell( $course, $lessonId );
		$view['tree']  = $this->preview->tree( $course, $lessonId );

		$groupId     = 0; // Предпросмотр не привязан к группе.
		$active_step = isset( $_GET['step'] ) ? sanitize_key( wp_unslash( $_GET['step'] ) ) : '';
		$can_edit    = current_user_can( Capability::AuthorLmsCourses->value );

		// Плеер — полноэкранный app-shell со своим <html> (см. LessonPlayerController):
		// Enqueue по этому флагу грузит бандл плеера вместо темы сайта.
		add_filter( 'fs_lms_is_player_route', '__return_true' );
		include $this->path( 'templates/frontend/lesson-player/player.php' );
		exit;
	}

	/** Текущая глубокая ссылка на курс/урок/шаг (для возврата после логина). */
	private function currentDeepLink(): string {
		$args = array( 'course' => (int) ( $_GET['course'] ?? 0 ) );
		if ( isset( $_GET['lesson'] ) ) {
			$args['lesson'] = (int) $_GET['lesson'];
		}
		if ( isset( $_GET['step'] ) ) {
			$args['step'] = sanitize_key( wp_unslash( $_GET['step'] ) );
		}

		return add_query_arg( $args, PageRoutes::CoursePreview->url() );
	}

	/** Отдаёт 404-шаблон (наличие/доступность курса постороннему не раскрываем). */
	private function notFound(): string {
		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();

		return get_404_template();
	}
}
