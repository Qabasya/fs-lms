<?php

declare( strict_types=1 );

namespace Inc\Controllers\Course;

use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\Enums\Course\GateState;
use Inc\Enums\Wp\PageRoutes;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Services\Course\CourseNavService;
use Inc\Services\Course\GroupAccessGuard;
use Inc\Services\Course\LessonGateResolver;
use Inc\Services\Course\LessonPlayerService;
use Inc\Services\Shared\ThemeCompatService;

/**
 * Class LessonPlayerController
 *
 * Пошаговый плеер урока (★, T1.5.12). Живёт на маршруте кокпита группы по `?gid=X&gl=Y`
 * (урок проходится в контексте программы группы). Регистрируется ДО `GroupCockpitController`:
 * при наличии `gl` рендерит плеер, иначе пропускает дальше (кокпит).
 *
 * @package Inc\Controllers
 */
class LessonPlayerController extends BaseController implements ServiceInterface {

	public function __construct(
		private readonly PersonRepository      $persons,
		private readonly GroupAccessGuard      $guard,
		private readonly GroupLessonRepository $groupLessons,
		private readonly LessonGateResolver    $gate,
		private readonly LessonPlayerService   $player,
		private readonly CourseNavService      $nav,
	) {
		parent::__construct();
	}

	public function register(): void {
		add_filter( 'template_include', array( $this, 'loadTemplate' ) );
	}

	public function loadTemplate( string $template ): string {
		// Обрабатываем только «проигрывание урока»: маршрут кокпита + параметр gl.
		if ( ! PageRoutes::GroupCockpit->isCurrent() || ! isset( $_GET['gl'] ) ) {
			return $template;
		}

		$userId = get_current_user_id();
		if ( ! $userId ) {
			// Глубокая ссылка на шаг могла прийти из соцсетей — после логина
			// возвращаем ученика ровно на этот урок/шаг.
			wp_redirect( wp_login_url( $this->currentDeepLink() ) );
			exit;
		}

		$row = $this->groupLessons->find( (int) $_GET['gl'] );
		if ( null === $row ) {
			return $this->notFound();
		}

		$person    = $this->persons->findByWpUserId( $userId );
		$isStudent = null !== $person && $this->guard->isMemberEver( $row->groupId, $person->id );
		if ( ! $isStudent ) {
			// Преподаватель группы — пусть кокпит отрисует свой обзор;
			// постороннему не раскрываем наличие урока (404).
			if ( $this->guard->canManage( $row->groupId, $userId ) ) {
				return $template;
			}
			return $this->notFound();
		}

		$lessonGate  = $this->gate->resolveLesson( $person->id, $row );
		$view        = $this->player->buildView( $person->id, $row );
		$groupId     = $row->groupId;
		$active_step = isset( $_GET['step'] ) ? sanitize_key( wp_unslash( $_GET['step'] ) ) : '';

		if ( GateState::Locked === $lessonGate || null === $view ) {
			ThemeCompatService::header();
			include $this->path( 'templates/frontend/lesson-player/locked.php' );
			ThemeCompatService::footer();
			exit;
		}

		// Оболочка плеера (T14.2) и дерево курса для рейки (T14.3).
		$view['shell'] = $this->nav->shell( $person->id, $row );
		$view['tree']  = $this->nav->tree( $person->id, $row );

		// Плеер — полноэкранный app-shell со своим <html> (Эпик 14, D18):
		// без темы сайта; Enqueue по этому флагу грузит только бандл плеера.
		add_filter( 'fs_lms_is_player_route', '__return_true' );
		include $this->path( 'templates/frontend/lesson-player/player.php' );
		exit;
	}

	/** Текущая глубокая ссылка на урок/шаг (для возврата после логина). */
	private function currentDeepLink(): string {
		$args = array( 'gl' => (int) ( $_GET['gl'] ?? 0 ) );
		if ( isset( $_GET['step'] ) ) {
			$args['step'] = sanitize_key( wp_unslash( $_GET['step'] ) );
		}

		return add_query_arg( $args, PageRoutes::GroupCockpit->url() );
	}

	/** Отдаёт 404-шаблон (наличие урока постороннему не раскрываем). */
	private function notFound(): string {
		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();

		return get_404_template();
	}
}
