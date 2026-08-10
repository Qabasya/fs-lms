<?php

declare( strict_types=1 );

namespace Inc\Controllers\Course;

use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\Enums\Wp\PageRoutes;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Services\Course\GroupAccessGuard;
use Inc\Services\Course\LessonPlayerService;
use Inc\Services\Shared\ThemeCompatService;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class LessonPlayerController
 *
 * Пошаговый плеер урока (★, T1.5.12). Живёт на своём маршруте `/lesson/?gid=X&gl=Y`
 * (урок проходится в контексте программы группы). Без `gl` шаблон не подменяется:
 * страница остаётся обычной страницей WP.
 *
 * @package Inc\Controllers
 */
class LessonPlayerController extends BaseController implements ServiceInterface {

	use Sanitizer;

	public function __construct(
		private readonly PersonRepository      $persons,
		private readonly GroupAccessGuard      $guard,
		private readonly GroupLessonRepository $groupLessons,
		private readonly LessonPlayerService   $player,
	) {
		parent::__construct();
	}

	public function register(): void {
		add_filter( 'template_include', array( $this, 'loadTemplate' ) );
	}

	public function loadTemplate( string $template ): string {
		// Обрабатываем только «проигрывание урока»: маршрут плеера + параметр gl.
		if ( ! PageRoutes::LessonPlayer->isCurrent() || ! $this->hasParam( 'gl', 'GET' ) ) {
			return $template;
		}

		$userId = get_current_user_id();
		if ( ! $userId ) {
			// Глубокая ссылка на шаг могла прийти из соцсетей — после логина
			// возвращаем ученика ровно на этот урок/шаг.
			wp_redirect( wp_login_url( $this->currentDeepLink() ) );
			exit;
		}

		$row = $this->groupLessons->find( $this->sanitizeGetInt( 'gl' ) );
		if ( null === $row ) {
			return $this->notFound();
		}

		$person    = $this->persons->findByWpUserId( $userId );
		$isStudent = null !== $person && $this->guard->isMemberEver( $row->groupId, $person->id );
		$isTeacher = false;

		if ( ! $isStudent ) {
			// Преподаватель группы — смотрит урок СВОЕЙ группы в teacher-режиме
			// плеера (Этап 2, ★): без прогресса ученика, все гейты открыты.
			// Постороннему не раскрываем наличие урока (404).
			if ( ! $this->guard->canManage( $row->groupId, $userId ) ) {
				return $this->notFound();
			}
			$isTeacher = true;
		}

		// Teacher-режим: personId=0 — нет ученика, прогресс не читается. Вся сборка
		// данных (view/shell/tree + расчёт блокировки) — в сервисе (Р2.7).
		$personId = $isTeacher ? 0 : $person->id;
		$data     = $this->player->buildRouteView( $personId, $row, $isTeacher );

		// Урок без контента (нет уроков-шагов) — прежний themed-фолбэк.
		if ( null === $data ) {
			ThemeCompatService::header();
			include $this->path( 'templates/frontend/lesson-player/locked.php' );
			ThemeCompatService::footer();
			exit;
		}

		// Локали для player.php (контракт шаблона неизменен). #3b: при блокировке по
		// времени/предусловию НЕ уводим на отдельную страницу — показываем плеер с
		// размытым контентом и оверлеем (+ таймер D-4); реальные шаги при $locked не
		// рендерятся (см. player.php) — контент не утекает раньше даты.
		$view             = $data['view'];
		$locked           = $data['locked'];
		$locked_scheduled = $data['locked_scheduled'];
		$locked_seconds   = $data['locked_seconds'];
		$locked_soon      = $data['locked_soon'];
		$groupId          = $row->groupId;
		$active_step      = $this->sanitizeGetKey( 'step' );
		$is_teacher       = $isTeacher;

		// Плеер — полноэкранный app-shell со своим <html> (Эпик 14, D18):
		// без темы сайта; Enqueue по этому флагу грузит только бандл плеера.
		add_filter( 'fs_lms_is_player_route', '__return_true' );
		include $this->path( 'templates/frontend/lesson-player/player.php' );
		exit;
	}

	/** Текущая глубокая ссылка на урок/шаг (для возврата после логина). */
	private function currentDeepLink(): string {
		$args = array( 'gl' => $this->sanitizeGetInt( 'gl' ) );
		$step = $this->sanitizeGetKey( 'step' );
		if ( '' !== $step ) {
			$args['step'] = $step;
		}

		return add_query_arg( $args, PageRoutes::LessonPlayer->url() );
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
