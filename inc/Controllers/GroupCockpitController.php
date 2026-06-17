<?php

declare( strict_types=1 );

namespace Inc\Controllers;

use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\Enums\Capability;
use Inc\Enums\PageRoutes;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\Log\LearningEventRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Course\GroupAccessGuard;
use Inc\Services\Course\ScheduleService;
use Inc\Services\ThemeCompatService;

class GroupCockpitController extends BaseController implements ServiceInterface {

	public function __construct(
		private readonly GroupAccessGuard        $guard,
		private readonly GroupsRepository        $groups,
		private readonly ScheduleService         $scheduleService,
		private readonly StudentRecordRepository $studentRecords,
		private readonly LearningEventRepository $eventRepo,
		private readonly GroupLessonRepository   $groupLessons,
	) {
		parent::__construct();
	}

	public function register(): void {
		add_filter( 'template_include', array( $this, 'loadTemplate' ) );
	}

	public function loadTemplate( string $template ): string {
		if ( ! PageRoutes::GroupCockpit->isCurrent() ) {
			return $template;
		}

		$userId = get_current_user_id();
		if ( ! $userId ) {
			wp_redirect( wp_login_url( PageRoutes::GroupCockpit->url() ) );
			exit;
		}

		$gid = isset( $_GET['gid'] ) ? (int) $_GET['gid'] : 0;

		if ( 0 === $gid ) {
			$this->renderGroupList( $userId );
			exit;
		}

		if ( ! $this->guard->canManage( $gid, $userId ) ) {
			wp_redirect( home_url( '/' ) );
			exit;
		}

		$this->renderCockpit( $gid, $userId );
		exit;

		return $template; // phpcs:ignore
	}

	private function renderGroupList( int $userId ): void {
		$isAdmin = user_can( $userId, Capability::Admin->value );
		$groups  = $isAdmin
			? $this->groups->findByFilters( '' )
			: $this->groups->findByFilters( '', '', $userId );

		ThemeCompatService::header();
		include $this->path( 'templates/frontend/group-cockpit/group-list.php' );
		ThemeCompatService::footer();
	}

	private function renderCockpit( int $groupId, int $userId ): void {
		$group    = $this->groups->findById( $groupId );
		$program  = $this->scheduleService->getProgram( $groupId );
		$roster   = $this->studentRecords->findActiveByGroupId( $groupId );
		$events   = $this->eventRepo->listByGroup( $groupId, 1, 20 );
		$total    = $this->eventRepo->countByGroup( $groupId );

		ThemeCompatService::header();
		include $this->path( 'templates/frontend/group-cockpit/cockpit.php' );
		ThemeCompatService::footer();
	}
}
