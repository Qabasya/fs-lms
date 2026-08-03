<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Course;

use Inc\Core\BaseController;
use Inc\Enums\Access\Capability;
use Inc\Enums\Wp\Nonce;
use Inc\Services\Course\GroupAccessGuard;
use Inc\Services\Group\GroupCalendarService;
use Inc\Services\Group\ProgramCompositionService;
use Inc\Services\Group\ScheduleReflowService;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\ProgramAccess;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class LessonScheduleCallbacks
 *
 * AJAX дат КТП: постановка занятия на дату, закрепление темы на день,
 * авто-раскладка и чтение календаря группы.
 *
 * @package Inc\Callbacks\Course
 *
 * Состав программы — {@see ProgramCallbacks}.
 */
class LessonScheduleCallbacks extends BaseController {

	use Authorizer;
	use ProgramAccess;
	use Sanitizer;

	/**
	 * @param ScheduleReflowService     $schedule Даты и раскладка
	 * @param GroupCalendarService      $calendar Представление КТП и свободные кабинеты
	 * @param ProgramCompositionService $program  Строки программы и состояние публикации
	 * @param GroupAccessGuard          $guard    Доступ к группе
	 */
	public function __construct(
		private readonly ScheduleReflowService     $schedule,
		private readonly GroupCalendarService      $calendar,
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
	 * Авто-распределение тем по слотам периода (кнопка «Распределить» в КТП).
	 * Params: group_id
	 */
	public function ajaxReflowSchedule(): void {
		$this->authorize( Nonce::SaveSchedule, Capability::ManageLmsTeaching );
		$groupId = $this->requireInt( 'group_id' );
		$userId  = get_current_user_id();

		$this->requireGroupAccess( $groupId );
		$this->denyIfProgramLocked( $groupId );

		$conflicts = $this->schedule->reflow( $groupId, $userId );
		$this->success( array( 'room_conflicts' => $conflicts ) );
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

		$row = $this->requireProgramRow( $groupLessonId );
		$this->denyIfProgramLocked( $row->groupId );

		if ( '' === $scheduledAt ) {
			$this->error( __( 'Не указана дата.', 'fs-lms' ) );
		}

		try {
			$this->schedule->pinToDate( $groupLessonId, $scheduledAt, $userId );
		} catch ( \InvalidArgumentException $e ) {
			$this->error( $e->getMessage() );
			return;
		}

		$this->success();
	}

	/**
	 * Календарь КТП группы: слоты периода, выходные и размещённые темы.
	 * Params: group_id
	 */
	public function ajaxGetGroupCalendar(): void {
		$this->authorize( Nonce::SaveSchedule, Capability::ManageLmsTeaching );
		$groupId = $this->requireInt( 'group_id' );

		$this->requireGroupAccess( $groupId );

		$this->success( $this->calendar->getCalendar( $groupId ) );
	}

	/**
	 * Свободные кабинеты для индивидуального занятия (Эпик 11 T11.3): по предмету
	 * группы + окну времени. Params: group_id, scheduled_at [, ends_at].
	 */
	public function ajaxGetFreeRooms(): void {
		$this->authorize( Nonce::SaveSchedule, Capability::ManageLmsTeaching );
		$groupId     = $this->requireInt( 'group_id' );
		$scheduledAt = $this->sanitizeText( 'scheduled_at' );
		$endsAt      = $this->sanitizeText( 'ends_at' ) ?: null;

		if ( '' === $scheduledAt ) {
			$this->error( __( 'Не указана дата занятия.', 'fs-lms' ) );
		}
		$this->requireGroupAccess( $groupId );

		$this->success( array( 'rooms' => $this->calendar->freeRoomsForGroup( $groupId, $scheduledAt, $endsAt ) ) );
	}
}
