<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Course;

use Inc\Core\BaseController;
use Inc\Enums\Access\Capability;
use Inc\Enums\Course\AssignmentPolicy;
use Inc\Enums\Wp\Nonce;
use Inc\Repositories\WPDBRepositories\Log\LearningEventRepository;
use Inc\Services\Course\CourseAssignmentService;
use Inc\Services\Course\GroupAccessGuard;
use Inc\Services\Group\ProgramCompositionService;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\ProgramAccess;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class ProgramCallbacks
 *
 * AJAX состава КТП: назначение курса, дублирование и продолжение темы, порядок,
 * публикация КТП и лента событий группы.
 *
 * @package Inc\Callbacks\Course
 *
 * Даты и раскладка — {@see LessonScheduleCallbacks}; индивидуальные занятия —
 * {@see IndividualLessonCallbacks}; ростер — {@see GroupRosterCallbacks};
 * дедлайны работ и запись занятия — {@see LessonDeliveryCallbacks}.
 */
class ProgramCallbacks extends BaseController {

	use Authorizer;
	use ProgramAccess;
	use Sanitizer;

	/**
	 * @param ProgramCompositionService $program            Состав и публикация программы
	 * @param LessonVisibilityService   $visibilityService  Видимость занятия для учеников
	 * @param CourseAssignmentService   $assignmentService  Назначение курса группе
	 * @param GroupAccessGuard          $guard              Доступ к группе
	 * @param LearningEventRepository   $eventRepo          Лента событий обучения
	 * @param GroupLessonRepository     $groupLessons       Строки программы
	 * @param PostManager               $posts              Записи (уроки, задания)
	 */
	public function __construct(
		private readonly ProgramCompositionService $program,
		private readonly CourseAssignmentService   $assignmentService,
		private readonly GroupAccessGuard          $guard,
		private readonly LearningEventRepository   $eventRepo,
	) {
		parent::__construct();
	}

	protected function accessGuard(): GroupAccessGuard {
		return $this->guard;
	}

	protected function programService(): ProgramCompositionService {
		return $this->program;
	}

	public function ajaxAssignCourse(): void {
		$this->authorize( Nonce::AssignCourse, Capability::ManageLmsTeaching );
		$groupId  = $this->requireInt( 'group_id' );
		$courseId = $this->requireInt( 'course_id' );
		$policy   = AssignmentPolicy::fromValueOrDefault( $this->sanitizeKey( 'policy' ) );
		$userId   = get_current_user_id();

		$this->requireGroupAccess( $groupId );
		$this->denyIfProgramLocked( $groupId );

		try {
			$added = $this->assignmentService->assign( $groupId, $courseId, $userId, $policy );
		} catch ( \InvalidArgumentException $e ) {
			$this->error( $e->getMessage() );
			return;
		}

		// D-C: для открытой группы курс с задачами без автопроверки назначается
		// (не критично — там просто некому проверить вручную), но предупреждаем.
		$warnings = $this->assignmentService->warningsFor( $groupId, $courseId );

		$this->success( array( 'added' => $added, 'warnings' => $warnings ) );
	}

	/**
	 * Курсы предмета группы для пикера назначения в КТП (Эпик 11 T11.1).
	 * Params: group_id.
	 */
	public function ajaxGetSubjectCourses(): void {
		$this->authorize( Nonce::AssignCourse, Capability::ManageLmsTeaching );
		$groupId = $this->requireInt( 'group_id' );

		$this->requireGroupAccess( $groupId );

		$this->success( array( 'courses' => $this->assignmentService->coursesForGroup( $groupId ) ) );
	}

	/**
	 * Публикует КТП группы (T1.8): фиксирует структуру и расписание — дальнейшие
	 * правки программы блокируются до снятия публикации. Params: group_id.
	 */
	public function ajaxPublishProgram(): void {
		$this->authorize( Nonce::SaveSchedule, Capability::ManageLmsTeaching );
		$groupId = $this->requireInt( 'group_id' );
		$userId  = get_current_user_id();

		$this->requireGroupAccess( $groupId );

		$this->program->publishProgram( $groupId, $userId );
		$this->success( array( 'locked' => true, 'locked_at' => $this->program->programLockedAt( $groupId ) ) );
	}

	/**
	 * Снимает публикацию КТП (T1.8): возвращает возможность редактирования
	 * структуры и расписания программы. Params: group_id.
	 */
	public function ajaxUnpublishProgram(): void {
		$this->authorize( Nonce::SaveSchedule, Capability::ManageLmsTeaching );
		$groupId = $this->requireInt( 'group_id' );
		$userId  = get_current_user_id();

		$this->requireGroupAccess( $groupId );

		$this->program->unpublishProgram( $groupId, $userId );
		$this->success( array( 'locked' => false, 'locked_at' => null ) );
	}

	/**
	 * Продолжает тему на вторую дату (T12.6, D14): новая связанная строка в банке
	 * тем (непристроена, пиннута) — пользователь перетаскивает её на целевую дату
	 * тем же drag-flow, что и обычную тему. Params: group_lesson_id.
	 */
	public function ajaxContinueProgramLesson(): void {
		$this->authorize( Nonce::SaveSchedule, Capability::ManageLmsTeaching );
		$groupLessonId = $this->requireInt( 'group_lesson_id' );
		$userId        = get_current_user_id();

		$row = $this->requireProgramRow( $groupLessonId );
		$this->denyIfProgramLocked( $row->groupId );

		$id = $this->program->continueLesson( $groupLessonId, $userId );
		if ( 0 === $id ) {
			$this->error( __( 'Нельзя продолжить: тема не найдена или уже является продолжением.', 'fs-lms' ) );
			return;
		}

		$this->success( array( 'group_lesson_id' => $id ) );
	}

	public function ajaxGetGroupProgram(): void {
		$this->authorize( Nonce::SaveSchedule, Capability::ManageLmsTeaching );
		$groupId = $this->requireInt( 'group_id' );

		$this->requireGroupAccess( $groupId );

		$this->success( $this->program->getProgram( $groupId ) );
	}

	public function ajaxGetGroupActivity(): void {
		$this->authorize( Nonce::GroupActivity, Capability::ManageLmsTeaching );
		$groupId = $this->requireInt( 'group_id' );
		$page    = max( 1, $this->sanitizeInt( 'page' ) );

		$this->requireGroupAccess( $groupId );

		$events = $this->eventRepo->listByGroup( $groupId, $page, 20 );

		$this->success( array(
			'events' => array_map( fn( $e ) => array(
				'action'     => $e->action,
				'actor'      => $e->actorUserId ? ( get_userdata( $e->actorUserId )->display_name ?? '' ) : '',
				'created_at' => $e->createdAt,
				'is_public'  => $e->isPublic,
			), $events ),
			'total'  => $this->eventRepo->countByGroup( $groupId ),
			'page'   => $page,
		) );
	}
}
