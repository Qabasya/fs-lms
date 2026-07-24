<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Course;

use Inc\Core\BaseController;
use Inc\DTO\Course\LessonDTO;
use Inc\DTO\Course\StepDTO;
use Inc\Enums\Access\Capability;
use Inc\Enums\Wp\Nonce;
use Inc\Enums\Wp\PostMetaName;
use Inc\Managers\Course\LessonManager;
use Inc\Managers\Wp\PostManager;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Services\Assessment\TaskPreviewService;
use Inc\Services\Course\ContentCloneService;
use Inc\Services\Course\GroupAccessGuard;
use Inc\Services\Course\LessonAuthoringService;
use Inc\Services\Course\LessonVisibilityService;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class TeacherLessonCallbacks
 *
 * AJAX-обработчики преподавателя над уроком занятия своей группы (Этап 3):
 * состав шагов (copy-on-write форк при первой правке) и read-only банк
 * (кандидаты-шаги, превью задачи/работы/контрольной). Клиент всегда передаёт
 * `group_lesson_id`, никогда `lesson_id` напрямую — сервер сам резолвит урок
 * (мастер или уже существующий форк группы) и форкает при необходимости
 * (`ContentCloneService::forkLessonForGroup`, идемпотентно).
 *
 * Экшены создания черновиков (задача/работа/контрольная/статья) преподавателю
 * не дублируются — только выбор из уже существующего банка методиста.
 *
 * @package Inc\Callbacks\Course
 */
class TeacherLessonCallbacks extends BaseController {

	use Authorizer;
	use Sanitizer;

	public function __construct(
		private readonly GroupLessonRepository   $groupLessons,
		private readonly GroupAccessGuard        $guard,
		private readonly LessonManager           $lessons,
		private readonly ContentCloneService     $cloneService,
		private readonly LessonAuthoringService  $authoringService,
		private readonly LessonVisibilityService $visibilityService,
		private readonly PostManager             $posts,
		private readonly TaskPreviewService      $taskPreview,
	) {
		parent::__construct();
	}

	/**
	 * Состав шагов урока занятия своей группы (для монтирования редактора, Этап 4).
	 * Params: group_lesson_id
	 */
	public function ajaxGetGroupLessonSteps(): void {
		$this->authorize( Nonce::TeachLesson, Capability::ManageLmsTeaching );

		$groupLessonId = $this->requireInt( 'group_lesson_id' );
		$row           = $this->groupLessons->find( $groupLessonId );
		if ( null === $row || ! $this->guard->canManage( $row->groupId, get_current_user_id() ) ) {
			$this->error( 'Занятие не найдено.' );
			return;
		}

		$lesson = null !== $row->lessonId ? $this->lessons->get( $row->lessonId ) : null;
		if ( null === $lesson ) {
			$this->error( 'У этого занятия ещё нет урока.' );
			return;
		}

		$isForked = $row->groupId > 0
			&& (int) $this->posts->getMeta( $row->lessonId, PostMetaName::ForkedForGroup->value ) === $row->groupId;

		$steps = StepDTO::toList( $lesson->steps );
		foreach ( $steps as &$step ) {
			$ref_id = (int) ( $step['payload']['ref'] ?? $step['payload']['article_id'] ?? 0 );
			if ( $ref_id > 0 ) {
				$step['_title'] = get_the_title( $ref_id );
			}
		}
		unset( $step );

		$this->success( array(
			'lesson_id'   => $lesson->id,
			'subject_key' => $lesson->subjectKey,
			'is_forked'   => $isForked,
			'steps'       => $steps,
		) );
	}

	/**
	 * Сохраняет состав шагов урока занятия своей группы: COW-форк при первой
	 * правке (безусловный вызов — `forkLessonForGroup` сам идемпотентен),
	 * дальше санитайз/сборка/лимит тем же путём, что и у методиста.
	 * Params: group_lesson_id, steps[]
	 */
	public function ajaxSaveGroupLessonSteps(): void {
		$this->authorize( Nonce::TeachLesson, Capability::ManageLmsTeaching );

		$groupLessonId = $this->requireInt( 'group_lesson_id' );
		$row           = $this->groupLessons->find( $groupLessonId );
		if ( null === $row || ! $this->guard->canManage( $row->groupId, get_current_user_id() ) ) {
			$this->error( 'Занятие не найдено.' );
			return;
		}
		if ( null === $row->lessonId ) {
			$this->error( 'У этого занятия ещё нет урока.' );
			return;
		}

		$forkId = $this->cloneService->forkLessonForGroup( $row->groupId, $groupLessonId );
		if ( $forkId <= 0 ) {
			$this->error( 'Не удалось подготовить урок группы.' );
			return;
		}

		$lesson = $this->lessons->get( $forkId );
		if ( null === $lesson ) {
			$this->error( 'Урок не найден.' );
			return;
		}

		$raw_steps = wp_unslash( $_POST['steps'] ?? array() );
		$raw_steps = is_array( $raw_steps ) ? $raw_steps : array();

		$sanitized = array_map( array( $this->authoringService, 'sanitizeStep' ), $raw_steps );
		$steps     = $this->authoringService->buildSteps( $sanitized );

		if ( count( $steps ) > LessonAuthoringService::MAX_STEPS_PER_LESSON ) {
			$this->error( sprintf( 'В одном уроке не может быть больше %d шагов.', LessonAuthoringService::MAX_STEPS_PER_LESSON ) );
			return;
		}

		$dto = new LessonDTO(
			id        : $forkId,
			subjectKey: $lesson->subjectKey,
			topic     : $lesson->topic,
			steps     : $steps,
			authorId  : $lesson->authorId,
			status    : $lesson->status,
		);
		$this->lessons->update( $forkId, $dto );
		// Уже открытым для групп занятиям — доложить новые работы урока (copy-on-publish).
		$this->visibilityService->syncExtraWorksForOpenOccurrences( $forkId );

		$this->success( array(
			'count'  => count( $steps ),
			// После forkLessonForGroup урок занятия ГАРАНТИРОВАННО форк этой группы
			// (создан сейчас или уже существовал) — потребитель тоста Этапа 4
			// («Урок скопирован для вашей группы — изменения не затронут курс»).
			'forked' => true,
		) );
	}

	/**
	 * Read-only банк кандидатов-шагов (без экшенов создания черновиков).
	 * Params: group_lesson_id, kind (work|task|assessment|article|lesson), source (subject|bank), search
	 */
	public function ajaxTeacherStepCandidates(): void {
		$this->authorize( Nonce::TeachLesson, Capability::ManageLmsTeaching );

		$groupLessonId = $this->requireInt( 'group_lesson_id' );
		$row           = $this->groupLessons->find( $groupLessonId );
		if ( null === $row || ! $this->guard->canManage( $row->groupId, get_current_user_id() ) || null === $row->lessonId ) {
			$this->error( 'Занятие не найдено.' );
			return;
		}

		$lesson = $this->lessons->get( $row->lessonId );
		if ( null === $lesson ) {
			$this->error( 'У этого занятия ещё нет урока.' );
			return;
		}

		$kind   = $this->sanitizeKey( 'kind' );
		$source = $this->sanitizeKey( 'source' );
		$search = $this->sanitizeText( 'search' );

		$this->success( $this->authoringService->getStepCandidates( $lesson->subjectKey, $kind, $source, $search ) );
	}

	/**
	 * Превью задачи для пикера шага (read-only, без привязки к конкретной группе —
	 * как и у методиста, доступ к самой задаче/работе/контрольной не завязан на занятие).
	 * Params: task_id
	 */
	public function ajaxTeacherTaskPreview(): void {
		$this->authorize( Nonce::TeachLesson, Capability::ManageLmsTeaching );

		$task_id = $this->requireInt( 'task_id' );
		$data    = $this->taskPreview->getTaskPreview( $task_id );
		if ( null === $data ) {
			$this->error( 'Задача не найдена.' );
			return;
		}

		$this->success( $data );
	}

	/**
	 * Превью работы/контрольной для пикера шага (read-only).
	 * Params: ref_id, ref_type (work|assessment)
	 */
	public function ajaxTeacherRefPreview(): void {
		$this->authorize( Nonce::TeachLesson, Capability::ManageLmsTeaching );

		$ref_id   = $this->requireInt( 'ref_id' );
		$ref_type = $this->sanitizeKey( 'ref_type' );

		$data = $this->taskPreview->getRefPreview( $ref_id, $ref_type );
		if ( null === $data ) {
			$this->error( 'Не найдено.' );
			return;
		}

		$this->success( $data );
	}
}
