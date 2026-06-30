<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Course;

use Inc\Core\BaseController;
use Inc\DTO\Course\StepDTO;
use Inc\Enums\Access\Capability;
use Inc\Enums\Course\AssignmentPolicy;
use Inc\Enums\Course\LessonVisibility;
use Inc\Enums\Course\StepType;
use Inc\Enums\Wp\Nonce;
use Inc\Enums\Wp\PostMetaName;
use Inc\Managers\Wp\PostManager;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\Log\LearningEventRepository;
use Inc\Services\Course\CourseAssignmentService;
use Inc\Services\Course\EffectiveWorksResolver;
use Inc\Services\Course\GroupAccessGuard;
use Inc\Services\Course\LessonVisibilityService;
use Inc\Services\Group\ScheduleService;
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
		private readonly GroupLessonRepository   $groupLessons,
		private readonly PostManager             $posts,
	) {
		parent::__construct();
	}

	public function ajaxAssignCourse(): void {
		$this->authorize( Nonce::AssignCourse, Capability::ManageLmsTeaching );
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
		$this->authorize( Nonce::SaveSchedule, Capability::ManageLmsTeaching );
		$groupId  = $this->requireInt( 'group_id' );
		$lessonId = $this->requireInt( 'lesson_id' );
		$label    = $this->sanitizeText( 'label' ) ?: null;
		$userId   = get_current_user_id();

		if ( ! $this->guard->canManage( $groupId, $userId ) ) {
			$this->error( __( 'Нет доступа к группе.', 'fs-lms' ) );
		}

		$id = $this->scheduleService->addLesson( $groupId, $lessonId, $userId, $label );
		$this->success( array( 'group_lesson_id' => $id ) );
	}

	public function ajaxDuplicateProgramLesson(): void {
		$this->authorize( Nonce::SaveSchedule, Capability::ManageLmsTeaching );
		$groupLessonId = $this->requireInt( 'group_lesson_id' );
		$userId        = get_current_user_id();

		$row = $this->scheduleService->getProgramRow( $groupLessonId );
		if ( null === $row || ! $this->guard->canManage( $row->groupId, $userId ) ) {
			$this->error( __( 'Нет доступа к группе.', 'fs-lms' ) );
		}

		$id = $this->scheduleService->duplicateLesson( $groupLessonId, $userId );
		$this->success( array( 'group_lesson_id' => $id ) );
	}

	public function ajaxRemoveLessonFromProgram(): void {
		$this->authorize( Nonce::SaveSchedule, Capability::ManageLmsTeaching );
		$groupLessonId = $this->requireInt( 'group_lesson_id' );
		$userId        = get_current_user_id();

		// Guard проверяется по владению записью внутри ScheduleService (group.teacher_id).
		$this->scheduleService->removeLesson( $groupLessonId, $userId );
		$this->success();
	}

	public function ajaxReorderProgram(): void {
		$this->authorize( Nonce::SaveSchedule, Capability::ManageLmsTeaching );
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
		$this->authorize( Nonce::SaveSchedule, Capability::ManageLmsTeaching );
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
		$this->authorize( Nonce::SaveSchedule, Capability::ManageLmsTeaching );
		$groupLessonId = $this->requireInt( 'group_lesson_id' );
		$workIds       = array_map( 'intval', (array) ( $_POST['work_ids'] ?? array() ) );
		$userId        = get_current_user_id();

		$this->worksResolver->setExtraWorks( $groupLessonId, $workIds, $userId );
		$this->success();
	}

	public function ajaxSetLessonVisibility(): void {
		$this->authorize( Nonce::SetLessonVisibility, Capability::ManageLmsTeaching );
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
		$this->authorize( Nonce::SaveSchedule, Capability::ManageLmsTeaching );
		$groupId = $this->requireInt( 'group_id' );
		$userId  = get_current_user_id();

		if ( ! $this->guard->canManage( $groupId, $userId ) ) {
			$this->error( __( 'Нет доступа к группе.', 'fs-lms' ) );
		}

		$this->success( $this->scheduleService->getProgram( $groupId ) );
	}

	/**
	 * Авто-распределение тем по слотам периода (кнопка «Распределить» в КТП).
	 * Params: group_id
	 */
	public function ajaxReflowSchedule(): void {
		$this->authorize( Nonce::SaveSchedule, Capability::ManageLmsTeaching );
		$groupId = $this->requireInt( 'group_id' );
		$userId  = get_current_user_id();

		if ( ! $this->guard->canManage( $groupId, $userId ) ) {
			$this->error( __( 'Нет доступа к группе.', 'fs-lms' ) );
		}

		$this->scheduleService->reflow( $groupId, $userId );
		$this->success();
	}

	/**
	 * Закрепляет тему на дату (drag-drop темы на день календаря).
	 * Params: group_lesson_id, scheduled_at
	 */
	public function ajaxPinLesson(): void {
		$this->authorize( Nonce::SaveSchedule, Capability::ManageLmsTeaching );
		$groupLessonId = $this->requireInt( 'group_lesson_id' );
		$scheduledAt   = $this->sanitizeText( 'scheduled_at' );
		$userId        = get_current_user_id();

		$row = $this->scheduleService->getProgramRow( $groupLessonId );
		if ( null === $row || ! $this->guard->canManage( $row->groupId, $userId ) ) {
			$this->error( __( 'Нет доступа к группе.', 'fs-lms' ) );
		}
		if ( '' === $scheduledAt ) {
			$this->error( __( 'Не указана дата.', 'fs-lms' ) );
		}

		$this->scheduleService->pinToDate( $groupLessonId, $scheduledAt, $userId );
		$this->success();
	}

	/**
	 * Календарь КТП группы: слоты периода, выходные и размещённые темы.
	 * Params: group_id
	 */
	public function ajaxGetGroupCalendar(): void {
		$this->authorize( Nonce::SaveSchedule, Capability::ManageLmsTeaching );
		$groupId = $this->requireInt( 'group_id' );
		$userId  = get_current_user_id();

		if ( ! $this->guard->canManage( $groupId, $userId ) ) {
			$this->error( __( 'Нет доступа к группе.', 'fs-lms' ) );
		}

		$this->success( $this->scheduleService->getCalendar( $groupId ) );
	}

	/**
	 * Возвращает список task-шагов урока с базовыми настройками и переопределениями группы.
	 * Используется в панели настроек шагов кокпита (Этап 6, Фаза D).
	 * Params: group_lesson_id
	 */
	public function ajaxGetStepSettings(): void {
		$this->authorize( Nonce::StepSettings, Capability::ManageLmsTeaching );
		$groupLessonId = $this->requireInt( 'group_lesson_id' );

		$groupLesson = $this->groupLessons->find( $groupLessonId );
		if ( ! $groupLesson || ! $groupLesson->lessonId ) {
			$this->error( 'Занятие не найдено.' );
			return;
		}

		$meta   = $this->posts->getMeta( $groupLesson->lessonId, PostMetaName::Meta->value );
		$steps  = StepDTO::fromList( is_array( $meta ) ? ( $meta['steps'] ?? array() ) : array() );
		$overrides = $groupLesson->stepSettingsOverrides ?? array();

		$result = array();
		foreach ( $steps as $step ) {
			if ( StepType::Task !== $step->type ) {
				continue;
			}

			$taskId  = (int) ( $step->payload['ref'] ?? 0 );
			$label   = $taskId ? ( $this->posts->get( $taskId )?->post_title ?? '' ) : '';
			$base    = array(
				'max_attempts'      => (int) ( $step->payload['settings']['max_attempts'] ?? 0 ),
				'shuffle'           => (bool) ( $step->payload['settings']['shuffle'] ?? false ),
				'hint_after_errors' => (int) ( $step->payload['settings']['hint_after_errors'] ?? 0 ),
			);
			$override = is_array( $overrides[ $step->key ] ?? null ) ? $overrides[ $step->key ] : null;

			$result[] = array(
				'key'      => $step->key,
				'label'    => $label ?: $step->key,
				'task_id'  => $taskId,
				'settings' => $base,
				'override' => $override,
			);
		}

		$this->success( array( 'steps' => $result ) );
	}

	/**
	 * Сохраняет переопределения настроек шагов для группового занятия.
	 * Params: group_lesson_id, overrides (JSON: {step_key: {max_attempts, shuffle, hint_after_errors}})
	 */
	public function ajaxSaveStepSettings(): void {
		$this->authorize( Nonce::StepSettings, Capability::ManageLmsTeaching );
		$groupLessonId = $this->requireInt( 'group_lesson_id' );
		$rawOverrides  = $this->sanitizeText( 'overrides' );

		$decoded = json_decode( $rawOverrides, true );
		if ( ! is_array( $decoded ) ) {
			$this->error( 'Неверный формат данных.' );
			return;
		}

		$sanitized = array();
		foreach ( $decoded as $stepKey => $values ) {
			if ( ! is_string( $stepKey ) || ! is_array( $values ) ) {
				continue;
			}
			$sanitized[ sanitize_key( $stepKey ) ] = array(
				'max_attempts'      => max( 0, (int) ( $values['max_attempts'] ?? 0 ) ),
				'shuffle'           => (bool) ( $values['shuffle'] ?? false ),
				'hint_after_errors' => max( 0, (int) ( $values['hint_after_errors'] ?? 0 ) ),
			);
		}

		$this->groupLessons->setStepSettingsOverrides( $groupLessonId, $sanitized );
		$this->success( array( 'saved' => true ) );
	}

	public function ajaxGetGroupActivity(): void {
		$this->authorize( Nonce::SaveSchedule, Capability::ManageLmsTeaching );
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
