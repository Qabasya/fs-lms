<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Course;

use Inc\Core\BaseController;
use Inc\Enums\Access\Capability;
use Inc\Enums\Course\AccessMode;
use Inc\Enums\Wp\Nonce;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Services\Course\AttendanceService;
use Inc\Services\Course\GroupAccessGuard;
use Inc\Services\Course\JournalService;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class JournalCallbacks
 *
 * AJAX журнала группы (ЛК преподавателя/офиса, Эпик 2): чтение журнала + посещаемость.
 * Чтение — `canManage` (препод по своей группе; офис/админ — по `ManageLmsPlatform`).
 * Запись посещаемости — `canWriteJournal`: в период активной замены постоянный
 * препод переходит в read-only, отмечает только замещающий (T5.7).
 *
 * @package Inc\Callbacks\Course
 */
class JournalCallbacks extends BaseController {

	use Authorizer;
	use Sanitizer;

	public function __construct(
		private readonly AttendanceService     $attendance,
		private readonly JournalService        $journal,
		private readonly GroupLessonRepository $groupLessons,
		private readonly GroupAccessGuard      $guard,
		private readonly GroupsRepository      $groups,
	) {
		parent::__construct();
	}

	/**
	 * Журнал группы: ростер × (занятия + работы). Params: group_id
	 */
	public function ajaxGetGroupJournal(): void {
		$this->authorize( Nonce::SaveSchedule, Capability::ManageLmsTeaching );
		$groupId = $this->requireInt( 'group_id' );
		$userId  = get_current_user_id();

		if ( ! $this->guard->canManage( $groupId, $userId ) ) {
			$this->error( __( 'Нет доступа к группе.', 'fs-lms' ) );
		}

		$this->success( $this->journal->forGroup( $groupId ) );
	}

	/**
	 * Отметка посещаемости одного ученика. Params: group_lesson_id, student_person_id, is_present
	 */
	public function ajaxSaveAttendance(): void {
		$this->authorize( Nonce::SaveSchedule, Capability::ManageLmsTeaching );
		$groupLessonId = $this->requireInt( 'group_lesson_id' );
		$personId      = $this->requireInt( 'student_person_id' );
		$present       = $this->sanitizeBool( 'is_present' );
		$userId        = get_current_user_id();

		$row = $this->groupLessons->find( $groupLessonId );
		if ( ! $row || ! $this->guard->canWriteJournal( $row->groupId, $userId ) ) {
			$this->error( __( 'Нет доступа к группе.', 'fs-lms' ) );
		}
		$this->guardNotFuture( $row );

		$this->attendance->mark( $groupLessonId, $personId, $present, $userId );
		$this->success();
	}

	/**
	 * Отметить всех учеников занятия present/absent. Params: group_lesson_id, is_present
	 */
	public function ajaxBulkAttendance(): void {
		$this->authorize( Nonce::SaveSchedule, Capability::ManageLmsTeaching );
		$groupLessonId = $this->requireInt( 'group_lesson_id' );
		$present       = $this->sanitizeBool( 'is_present' );
		$userId        = get_current_user_id();

		$row = $this->groupLessons->find( $groupLessonId );
		if ( ! $row || ! $this->guard->canWriteJournal( $row->groupId, $userId ) ) {
			$this->error( __( 'Нет доступа к группе.', 'fs-lms' ) );
		}
		$this->guardNotFuture( $row );

		$this->attendance->markAll( $groupLessonId, $present, $userId );
		$this->success();
	}

	/**
	 * Защита журнала (D11): нельзя отмечать посещаемость на ещё не прошедшем занятии
	 * (дата > сегодня). Редактируемы только занятия с датой ≤ текущей.
	 * Эпик 15: в открытой группе занятия не датируются — посещаемость не ведётся вовсе.
	 */
	private function guardNotFuture( \Inc\DTO\Course\GroupLessonDTO $row ): void {
		$group = $this->groups->findById( $row->groupId );
		if ( AccessMode::Open === AccessMode::fromValueOrDefault( (string) ( $group->access_mode ?? '' ) ) ) {
			$this->error( __( 'В открытой группе посещаемость не ведётся.', 'fs-lms' ) );
		}
		if ( $row->scheduledAt && substr( $row->scheduledAt, 0, 10 ) > current_time( 'Y-m-d' ) ) {
			$this->error( __( 'Занятие ещё не прошло — отметить посещаемость нельзя.', 'fs-lms' ) );
		}
	}
}
