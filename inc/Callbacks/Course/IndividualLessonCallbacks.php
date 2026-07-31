<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Course;

use Inc\Core\BaseController;
use Inc\Enums\Access\Capability;
use Inc\Enums\Wp\Nonce;
use Inc\Services\Course\GroupAccessGuard;
use Inc\Services\Group\IndividualLessonService;
use Inc\Services\Group\ProgramCompositionService;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\ProgramAccess;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class IndividualLessonCallbacks
 *
 * AJAX индивидуальных занятий (Эпик 4, D3): создание, список слотов группы,
 * подбор урока и правка занятия.
 *
 * @package Inc\Callbacks\Course
 */
class IndividualLessonCallbacks extends BaseController {

	use Authorizer;
	use ProgramAccess;
	use Sanitizer;

	/**
	 * @param IndividualLessonService   $individual Индивидуальные занятия
	 * @param ProgramCompositionService $program    Строки программы (доступ к занятию)
	 * @param GroupAccessGuard          $guard      Доступ к группе
	 */
	public function __construct(
		private readonly IndividualLessonService   $individual,
		private readonly ProgramCompositionService $program,
		private readonly GroupAccessGuard          $guard,
	) {
		parent::__construct();
	}

	protected function accessGuard(): GroupAccessGuard {
		return $this->guard;
	}

	protected function programService(): ProgramCompositionService {
		return $this->program;
	}

	/**
	 * Создаёт индивидуальное занятие на одного ученика (Эпик 4).
	 * Params: group_id, student_person_id, scheduled_at [, ends_at, lesson_id, label, teacher_user_id, room_id]
	 */
	public function ajaxCreateIndividualLesson(): void {
		$this->authorize( Nonce::SaveSchedule, Capability::ManageLmsTeaching );
		$groupId         = $this->requireInt( 'group_id' );
		$studentPersonId = $this->requireInt( 'student_person_id' );
		$scheduledAt     = $this->sanitizeText( 'scheduled_at' );
		$endsAt          = $this->sanitizeText( 'ends_at' ) ?: null;
		$lessonId        = $this->optionalInt( 'lesson_id' );
		$label           = $this->sanitizeText( 'label' ) ?: null;
		$teacherUserId   = $this->optionalInt( 'teacher_user_id' );
		$roomId          = $this->optionalInt( 'room_id' );
		$userId          = get_current_user_id();

		if ( '' === $scheduledAt ) {
			$this->error( __( 'Не указана дата занятия.', 'fs-lms' ) );
		}
		$this->requireGroupAccess( $groupId );

		try {
			$id = $this->individual->createIndividualLesson(
				$groupId, $studentPersonId, $scheduledAt, $endsAt, $lessonId, $label, $teacherUserId, $userId, $roomId
			);
		} catch ( \InvalidArgumentException $e ) {
			$this->error( $e->getMessage() );
			return;
		}

		$this->success( array( 'group_lesson_id' => $id ) );
	}

	/**
	 * НБ-9: индивидуальные занятия группы для режима КТП «Индивидуальные занятия»
	 * (ФИО + дата + урок/тема). Params: group_id.
	 */
	public function ajaxGetIndividualSlots(): void {
		$this->authorize( Nonce::SaveSchedule, Capability::ManageLmsTeaching );
		$groupId = $this->requireInt( 'group_id' );

		$this->requireGroupAccess( $groupId );

		$this->success( array( 'items' => $this->individual->getIndividualProgram( $groupId ) ) );
	}

	/**
	 * НБ-9: уроки предмета группы (курс-первыми) для назначения инд. занятию.
	 * Params: group_id [, search].
	 */
	public function ajaxGetLessonCandidates(): void {
		$this->authorize( Nonce::SaveSchedule, Capability::ManageLmsTeaching );
		$groupId = $this->requireInt( 'group_id' );
		$search  = $this->sanitizeText( 'search' );

		$this->requireGroupAccess( $groupId );

		$this->success( array( 'lessons' => $this->individual->lessonCandidatesForGroup( $groupId, $search ) ) );
	}

	/**
	 * НБ-9: привязывает урок банка к индивидуальному занятию.
	 * Params: group_lesson_id, lesson_id.
	 */
	public function ajaxAssignIndividualLesson(): void {
		$this->authorize( Nonce::SaveSchedule, Capability::ManageLmsTeaching );
		$groupLessonId = $this->requireInt( 'group_lesson_id' );
		$lessonId      = $this->requireInt( 'lesson_id' );
		$userId        = get_current_user_id();

		$this->requireProgramRow( $groupLessonId, __( 'Нет доступа к занятию.', 'fs-lms' ) );

		try {
			$this->individual->assignLessonToIndividual( $groupLessonId, $lessonId, $userId );
		} catch ( \InvalidArgumentException $e ) {
			$this->error( $e->getMessage() );
			return;
		}

		$this->success();
	}

	/**
	 * Правка индивидуального занятия (B2): дата/время, кабинет, ученик, урок (тема).
	 * Отсутствующие/пустые поля не меняются; room_id='0' очищает кабинет.
	 */
	public function ajaxUpdateIndividualLesson(): void {
		$this->authorize( Nonce::SaveSchedule, Capability::ManageLmsTeaching );
		$groupLessonId   = $this->requireInt( 'group_lesson_id' );
		$scheduledAt     = $this->optionalText( 'scheduled_at' );
		$endsAt          = $this->optionalText( 'ends_at' );
		$roomId          = $this->optionalInt( 'room_id' );
		$studentPersonId = $this->optionalInt( 'student_person_id' );
		$lessonId        = $this->optionalInt( 'lesson_id' );
		$userId          = get_current_user_id();

		$this->requireProgramRow( $groupLessonId, __( 'Нет доступа к занятию.', 'fs-lms' ) );

		try {
			$this->individual->updateIndividualLesson( $groupLessonId, $scheduledAt, $endsAt, $roomId, $studentPersonId, $lessonId, $userId );
		} catch ( \InvalidArgumentException $e ) {
			$this->error( $e->getMessage() );
			return;
		}

		$this->success();
	}

	/**
	 * Необязательное числовое поле: отсутствует или пустая строка → null.
	 *
	 * @param string $key Ключ в $_POST
	 */
	private function optionalInt( string $key ): ?int {
		return isset( $_POST[ $key ] ) && '' !== $_POST[ $key ] ? $this->sanitizeInt( $key ) : null;
	}

	/**
	 * Необязательное строковое поле: отсутствует или пустая строка → null.
	 *
	 * @param string $key Ключ в $_POST
	 */
	private function optionalText( string $key ): ?string {
		return isset( $_POST[ $key ] ) && '' !== $_POST[ $key ] ? $this->sanitizeText( $key ) : null;
	}
}
