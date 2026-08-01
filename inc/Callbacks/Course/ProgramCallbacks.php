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
use Inc\Services\Group\ProgramCompositionService;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\ProgramAccess;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class ProgramCallbacks
 *
 * AJAX состава КТП: назначение курса, темы программы, порядок, видимость,
 * публикация КТП, настройки шагов занятия и лента событий группы.
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
		private readonly LessonVisibilityService   $visibilityService,
		private readonly CourseAssignmentService   $assignmentService,
		private readonly GroupAccessGuard          $guard,
		private readonly LearningEventRepository   $eventRepo,
		private readonly GroupLessonRepository     $groupLessons,
		private readonly PostManager               $posts,
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

	public function ajaxAddLessonToProgram(): void {
		$this->authorize( Nonce::SaveSchedule, Capability::ManageLmsTeaching );
		$groupId  = $this->requireInt( 'group_id' );
		$lessonId = $this->requireInt( 'lesson_id' );
		$label    = $this->sanitizeText( 'label' ) ?: null;
		$userId   = get_current_user_id();

		$this->requireGroupAccess( $groupId );
		$this->denyIfProgramLocked( $groupId );

		$id = $this->program->addLesson( $groupId, $lessonId, $userId, $label );
		$this->success( array( 'group_lesson_id' => $id ) );
	}

	public function ajaxDuplicateProgramLesson(): void {
		$this->authorize( Nonce::SaveSchedule, Capability::ManageLmsTeaching );
		$groupLessonId = $this->requireInt( 'group_lesson_id' );
		$userId        = get_current_user_id();

		$row = $this->requireProgramRow( $groupLessonId );
		$this->denyIfProgramLocked( $row->groupId );

		$id = $this->program->duplicateLesson( $groupLessonId, $userId );
		$this->success( array( 'group_lesson_id' => $id ) );
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

	public function ajaxRemoveLessonFromProgram(): void {
		$this->authorize( Nonce::SaveSchedule, Capability::ManageLmsTeaching );
		$groupLessonId = $this->requireInt( 'group_lesson_id' );
		$userId        = get_current_user_id();

		$row = $this->requireProgramRow( $groupLessonId );
		$this->denyIfProgramLocked( $row->groupId );

		$this->program->removeLesson( $groupLessonId, $userId );
		$this->success();
	}

	public function ajaxReorderProgram(): void {
		$this->authorize( Nonce::SaveSchedule, Capability::ManageLmsTeaching );
		$groupId    = $this->requireInt( 'group_id' );
		$orderedIds = $this->sanitizeIntList( 'ordered_ids' );
		$userId     = get_current_user_id();

		$this->requireGroupAccess( $groupId );
		$this->denyIfProgramLocked( $groupId );

		$this->program->reorder( $groupId, $orderedIds, $userId );
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

		$this->requireGroupAccess( $groupId );

		$this->success( $this->program->getProgram( $groupId ) );
	}

	/**
	 * Возвращает список task-шагов урока с базовыми настройками и переопределениями группы.
	 * Панель настроек шагов (Этап 6, Фаза D). Фронтовый потребитель удалён вместе
	 * с кокпитом группы — хук оставлен до переноса функции в кабинет.
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

		$meta      = $this->posts->getMeta( $groupLesson->lessonId, PostMetaName::Meta->value );
		$steps     = StepDTO::fromList( is_array( $meta ) ? ( $meta['steps'] ?? array() ) : array() );
		$overrides = $groupLesson->stepSettingsOverrides ?? array();

		$result = array();
		foreach ( $steps as $step ) {
			if ( StepType::Task !== $step->type ) {
				continue;
			}

			$taskId = (int) ( $step->payload['ref'] ?? 0 );
			$label  = $taskId ? ( $this->posts->get( $taskId )?->post_title ?? '' ) : '';
			$base   = array(
				'max_attempts'      => (int) ( $step->payload['settings']['max_attempts'] ?? 0 ),
				'shuffle'           => (bool) ( $step->payload['settings']['shuffle'] ?? false ),
				'hint_after_errors' => (int) ( $step->payload['settings']['hint_after_errors'] ?? 0 ),
			);

			$result[] = array(
				'key'      => $step->key,
				'label'    => $label ?: $step->key,
				'task_id'  => $taskId,
				'settings' => $base,
				'override' => is_array( $overrides[ $step->key ] ?? null ) ? $overrides[ $step->key ] : null,
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
			$sanitized[ $this->sanitizeKeyValue( $stepKey ) ] = array(
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
