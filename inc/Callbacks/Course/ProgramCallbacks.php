<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Course;

use Inc\Core\BaseController;
use Inc\Enums\Course\AssignmentPolicy;
use Inc\Enums\Access\Capability;
use Inc\Enums\Course\LessonVisibility;
use Inc\Enums\Wp\Nonce;
use Inc\Repositories\WPDBRepositories\Log\LearningEventRepository;
use Inc\Services\Course\CourseAssignmentService;
use Inc\Services\Course\EffectiveWorksResolver;
use Inc\Services\Course\GroupAccessGuard;
use Inc\Services\Course\LessonVisibilityService;
use Inc\Services\Course\ScheduleService;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

class ProgramCallbacks extends BaseController {

	use Authorizer;
	use Sanitizer;

	public function __construct(
		private readonly ScheduleService         $scheduleService,
		private readonly LessonVisibilityService $visibilityService,
		private readonly CourseAssignmentService $assignmentService,
		private readonly EffectiveWorksResolver  $worksResolver,
		private readonly GroupAccessGuard        $guard,
		private readonly LearningEventRepository $eventRepo,
	) {
		parent::__construct();
	}

	public function ajaxAssignCourse(): void {
		$this->authorize( Nonce::AssignCourse, Capability::ManageLMSAssignments );
		$groupId  = $this->requireInt( 'group_id' );
		$courseId = $this->requireInt( 'course_id' );
		$policy   = AssignmentPolicy::fromValueOrDefault( $this->sanitizeKey( 'policy' ) );
		$userId   = get_current_user_id();

		if ( ! $this->guard->canManage( $groupId, $userId ) ) {
			$this->error( __( 'Нет доступа к группе.', 'fs-lms' ) );
		}

		$added = $this->assignmentService->assign( $groupId, $courseId, $userId, $policy );
		$this->success( array( 'added' => $added ) );
	}

	public function ajaxAddLessonToProgram(): void {
		$this->authorize( Nonce::SaveSchedule, Capability::ManageLMSAssignments );
		$groupId  = $this->requireInt( 'group_id' );
		$lessonId = $this->requireInt( 'lesson_id' );
		$userId   = get_current_user_id();

		if ( ! $this->guard->canManage( $groupId, $userId ) ) {
			$this->error( __( 'Нет доступа к группе.', 'fs-lms' ) );
		}

		$id = $this->scheduleService->addLesson( $groupId, $lessonId, $userId );
		$this->success( array( 'group_lesson_id' => $id ) );
	}

	public function ajaxRemoveLessonFromProgram(): void {
		$this->authorize( Nonce::SaveSchedule, Capability::ManageLMSAssignments );
		$groupLessonId = $this->requireInt( 'group_lesson_id' );
		$userId        = get_current_user_id();

		// Guard проверяется по владению записью внутри ScheduleService (group.teacher_id).
		$this->scheduleService->removeLesson( $groupLessonId, $userId );
		$this->success();
	}

	public function ajaxReorderProgram(): void {
		$this->authorize( Nonce::SaveSchedule, Capability::ManageLMSAssignments );
		$groupId    = $this->requireInt( 'group_id' );
		$orderedIds = array_map( 'intval', (array) ( $_POST['ordered_ids'] ?? array() ) );
		$userId     = get_current_user_id();

		if ( ! $this->guard->canManage( $groupId, $userId ) ) {
			$this->error( __( 'Нет доступа к группе.', 'fs-lms' ) );
		}

		$this->scheduleService->reorder( $groupId, $orderedIds, $userId );
		$this->success();
	}

	public function ajaxSaveLessonSchedule(): void {
		$this->authorize( Nonce::SaveSchedule, Capability::ManageLMSAssignments );
		$groupLessonId  = $this->requireInt( 'group_lesson_id' );
		$scheduledAt    = $this->sanitizeText( 'scheduled_at' ) ?: null;
		$teacherUserId  = isset( $_POST['teacher_user_id'] ) && '' !== $_POST['teacher_user_id']
			? $this->sanitizeInt( 'teacher_user_id' )
			: null;
		$userId         = get_current_user_id();

		$this->scheduleService->schedule( $groupLessonId, $scheduledAt, $teacherUserId, $userId );
		$this->success();
	}

	public function ajaxSetLessonExtraWorks(): void {
		$this->authorize( Nonce::SaveSchedule, Capability::ManageLMSAssignments );
		$groupLessonId = $this->requireInt( 'group_lesson_id' );
		$workIds       = array_map( 'intval', (array) ( $_POST['work_ids'] ?? array() ) );
		$userId        = get_current_user_id();

		$this->worksResolver->setExtraWorks( $groupLessonId, $workIds, $userId );
		$this->success();
	}

	public function ajaxSetLessonVisibility(): void {
		$this->authorize( Nonce::SetLessonVisibility, Capability::ManageLMSAssignments );
		$groupLessonId = $this->requireInt( 'group_lesson_id' );
		$visibility    = LessonVisibility::tryFrom( $this->sanitizeKey( 'visibility' ) );
		$userId        = get_current_user_id();

		if ( null === $visibility ) {
			$this->error( __( 'Неверное значение видимости.', 'fs-lms' ) );
		}

		$this->visibilityService->setVisibility( $groupLessonId, $visibility->value, $userId );
		$this->success();
	}

	public function ajaxGetGroupProgram(): void {
		$this->authorize( Nonce::SaveSchedule, Capability::ManageLMSAssignments );
		$groupId = $this->requireInt( 'group_id' );
		$userId  = get_current_user_id();

		if ( ! $this->guard->canManage( $groupId, $userId ) ) {
			$this->error( __( 'Нет доступа к группе.', 'fs-lms' ) );
		}

		$this->success( $this->scheduleService->getProgram( $groupId ) );
	}

	public function ajaxGetGroupActivity(): void {
		$this->authorize( Nonce::SaveSchedule, Capability::ManageLMSAssignments );
		$groupId = $this->requireInt( 'group_id' );
		$page    = max( 1, $this->sanitizeInt( 'page' ) );
		$userId  = get_current_user_id();

		if ( ! $this->guard->canManage( $groupId, $userId ) ) {
			$this->error( __( 'Нет доступа к группе.', 'fs-lms' ) );
		}

		$events = $this->eventRepo->listByGroup( $groupId, $page, 20 );
		$total  = $this->eventRepo->countByGroup( $groupId );

		$this->success( array(
			'events' => array_map( fn( $e ) => array(
				'action'     => $e->action,
				'actor'      => $e->actorUserId ? ( get_userdata( $e->actorUserId )->display_name ?? '' ) : '',
				'created_at' => $e->createdAt,
				'is_public'  => $e->isPublic,
			), $events ),
			'total'  => $total,
			'page'   => $page,
		) );
	}
}
