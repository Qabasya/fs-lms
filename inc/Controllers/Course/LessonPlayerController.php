<?php

declare( strict_types=1 );

namespace Inc\Controllers\Course;

use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\Enums\Course\GateState;
use Inc\Enums\Wp\PageRoutes;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
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
			wp_redirect( wp_login_url( PageRoutes::GroupCockpit->url() ) );
			exit;
		}

		$person = $this->persons->findByWpUserId( $userId );
		$row    = $this->groupLessons->find( (int) $_GET['gl'] );
		if ( null === $person || null === $row || ! $this->guard->isMemberEver( $row->groupId, $person->id ) ) {
			return $template; // не ученик этого урока — пусть кокпит решает (редирект)
		}

		$lessonGate = $this->gate->resolveLesson( $person->id, $row );
		$view       = $this->player->buildView( $person->id, $row );
		$groupId    = $row->groupId;

		ThemeCompatService::header();
		if ( GateState::Locked === $lessonGate || null === $view ) {
			include $this->path( 'templates/frontend/lesson-player/locked.php' );
		} else {
			include $this->path( 'templates/frontend/lesson-player/player.php' );
		}
		ThemeCompatService::footer();
		exit;
	}
}
