<?php

declare( strict_types=1 );

namespace Inc\Controllers\Group;

use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\Enums\Access\Capability;
use Inc\Enums\Wp\PageRoutes;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\Log\LearningEventRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Repositories\WPDBRepositories\SubmissionRepository;
use Inc\Managers\Course\CourseManager;
use Inc\Services\Course\EffectiveWorksResolver;
use Inc\Services\Course\GroupAccessGuard;
use Inc\Services\Group\ScheduleService;
use Inc\Services\Shared\ThemeCompatService;

class GroupCockpitController extends BaseController implements ServiceInterface {

	public function __construct(
		private readonly GroupAccessGuard        $guard,
		private readonly GroupsRepository        $groups,
		private readonly ScheduleService         $scheduleService,
		private readonly StudentRecordRepository $studentRecords,
		private readonly LearningEventRepository $eventRepo,
		private readonly GroupLessonRepository   $groupLessons,
		private readonly PersonRepository        $personRepo,
		private readonly EffectiveWorksResolver  $worksResolver,
		private readonly SubmissionRepository    $submissionRepo,
		private readonly CourseManager           $courseManager,
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

		if ( $this->guard->canManage( $gid, $userId ) ) {
			$this->renderCockpit( $gid, $userId );
			exit;
		}

		$person = $this->personRepo->findByWpUserId( $userId );
		if ( $person && $this->guard->isMemberEver( $gid, $person->id ) ) {
			$this->renderStudentCockpit( $gid, $person->id );
			exit;
		}

		wp_redirect( home_url( '/' ) );
		exit;

		return $template; // phpcs:ignore
	}

	private function renderGroupList( int $userId ): void {
		$isAdmin = user_can( $userId, Capability::Admin->value ) || user_can( $userId, Capability::ManageLmsPlatform->value );
		$groups  = $isAdmin
			? $this->groups->findByFilters( '' )
			: $this->groups->findByFilters( '', '', $userId );

		ThemeCompatService::header();
		include $this->path( 'templates/frontend/group-cockpit/group-list.php' );
		ThemeCompatService::footer();
	}

	private function renderCockpit( int $groupId, int $userId ): void {
		$group      = $this->groups->findById( $groupId );
		$subjectKey = $group->subject_key ?? '';
		$program    = $this->scheduleService->getProgram( $groupId );
		$roster     = $this->studentRecords->findActiveByGroupId( $groupId );
		$events     = $this->eventRepo->listByGroup( $groupId, 1, 20 );
		$total      = $this->eventRepo->countByGroup( $groupId );
		$courses    = $this->courseManager->getBankBySubject( $subjectKey );

		ThemeCompatService::header();
		include $this->path( 'templates/frontend/group-cockpit/cockpit.php' );
		ThemeCompatService::footer();
	}

	private function renderStudentCockpit( int $groupId, int $studentPersonId ): void {
		$group   = $this->groups->findById( $groupId );
		$program = $this->scheduleService->getProgram( $groupId );

		$lessons = [];
		foreach ( $program as $item ) {
			$row = $item['row'];
			if ( ! in_array( $row->visibility, [ 'open', 'archived' ], true ) ) {
				continue;
			}

			$effectiveWorks = $this->worksResolver->resolve( $row );
			$submissions    = $this->submissionRepo->listByStudentAndGroupLesson( $studentPersonId, $row->id );

			$lessons[] = [
				'row'         => $row,
				'topic'       => $item['topic'],
				'works'       => $effectiveWorks,
				'submissions' => $submissions,
			];
		}

		ThemeCompatService::header();
		include $this->path( 'templates/frontend/group-cockpit/student-cockpit.php' );
		ThemeCompatService::footer();
	}
}
