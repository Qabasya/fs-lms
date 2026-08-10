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
use Inc\Shared\Traits\Sanitizer;

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

	use Sanitizer;

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
		if ( ! PageRoutes::CoursePreview->isCurrent() || ! $this->hasParam( 'course', 'GET' ) ) {
			return $template;
		}

		$params = $this->deepLinkParams();

		$userId = get_current_user_id();
		if ( ! $userId ) {
			wp_redirect( wp_login_url( $this->currentDeepLink() ) );
			exit;
		}

		$courseId = $params['course'];
		if ( ! $this->access->canPreview( $userId, $courseId ) ) {
			// Постороннему не раскрываем наличие курса (404), как в LessonPlayerController.
			return $this->notFound();
		}

		$course = $this->courses->get( $courseId );
		if ( null === $course ) {
			return $this->notFound();
		}

		// Фолбэк на первый урок курса — только здесь: ссылка возврата (currentDeepLink)
		// не должна дописывать урок, которого не было в исходном запросе.
		$lessonId = $params['lesson'] ?? ( $course->lessonIds()[0] ?? 0 );
		$view     = $lessonId ? $this->preview->buildView( $courseId, $lessonId ) : null;
		if ( null === $view ) {
			return $this->notFound();
		}

		$view['shell'] = $this->preview->shell( $course, $lessonId );
		$view['tree']  = $this->preview->tree( $course, $lessonId );

		$groupId     = 0; // Предпросмотр не привязан к группе.
		$active_step = $params['step'];
		$can_edit    = current_user_can( Capability::AuthorLmsCourses->value );

		// Плеер — полноэкранный app-shell со своим <html> (см. LessonPlayerController):
		// Enqueue по этому флагу грузит бандл плеера вместо темы сайта.
		add_filter( 'fs_lms_is_player_route', '__return_true' );
		include $this->path( 'templates/frontend/lesson-player/player.php' );
		exit;
	}

	/**
	 * Единый разбор deep-link `?course=&lesson=&step=`.
	 *
	 * lesson === null — параметр не передан: фолбэк на первый урок курса делает
	 * только loadTemplate(), ссылка возврата урок не дописывает.
	 *
	 * @return array{course: int, lesson: ?int, step: string}
	 */
	private function deepLinkParams(): array {
		return array(
			'course' => $this->sanitizeGetInt( 'course' ),
			'lesson' => $this->hasParam( 'lesson', 'GET' ) ? $this->sanitizeGetInt( 'lesson' ) : null,
			'step'   => $this->sanitizeGetKey( 'step' ),
		);
	}

	/** Текущая глубокая ссылка на курс/урок/шаг (для возврата после логина). */
	private function currentDeepLink(): string {
		$params = $this->deepLinkParams();

		$args = array( 'course' => $params['course'] );
		if ( null !== $params['lesson'] ) {
			$args['lesson'] = $params['lesson'];
		}
		if ( $this->hasParam( 'step', 'GET' ) ) {
			$args['step'] = $params['step'];
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
