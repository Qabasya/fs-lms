<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\DTO\Course\GroupLessonDTO;
use Inc\Enums\Profile\NotificationType;
use Inc\Enums\Wp\PageRoutes;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\RoomRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Profile\NotificationService;

/**
 * Назначение кабинетов группам и занятиям (Эпик 9, R1/R2).
 *
 * R1: `groups.room_id` — дефолт на год; `group_lessons.room_id` — override/индивидуальные.
 * R2: конфликт по времени — hard-block (исключение); нехватка мест — мягкое
 * предупреждение (возвращается, не блокирует).
 *
 * @package Inc\Services\Course
 */
class RoomAssignmentService {

	public function __construct(
		private readonly RoomRepository            $rooms,
		private readonly GroupsRepository          $groups,
		private readonly GroupLessonRepository     $groupLessons,
		private readonly StudentRecordRepository   $records,
		private readonly NotificationService       $notifications,
	) {}

	/**
	 * Кабинет-по-умолчанию группы на год. Валидирует предмет; возвращает предупреждение
	 * о вместимости. `NULL` снимает кабинет.
	 *
	 * @return string[] предупреждения (напр. «мест меньше, чем учеников»).
	 * @throws \InvalidArgumentException группа/кабинет не найдены или предмет не разрешён.
	 */
	public function assignToGroup( int $groupId, ?int $roomId ): array {
		$group = $this->groups->findById( $groupId );
		if ( ! $group ) {
			throw new \InvalidArgumentException( 'Группа не найдена.' );
		}
		$warnings = array();

		if ( null !== $roomId ) {
			$room = $this->rooms->find( $roomId );
			if ( ! $room ) {
				throw new \InvalidArgumentException( 'Кабинет не найден.' );
			}
			if ( ! $room->allowsSubject( (string) $group->subject_key ) ) {
				throw new \InvalidArgumentException( 'Кабинет не предназначен для предмета группы.' );
			}
			$students = $this->records->countActiveByGroup( $groupId );
			if ( $room->seats > 0 && $room->seats < $students ) {
				$warnings[] = sprintf( 'В кабинете %d мест, а в группе %d учеников.', $room->seats, $students );
			}
		}

		$this->groups->update( $groupId, array( 'room_id' => $roomId ) );
		return $warnings;
	}

	/**
	 * Кабинет конкретного занятия (override/индивидуальные). Hard-block по конфликту времени.
	 *
	 * @throws \InvalidArgumentException занятие/кабинет не найдены; конфликт по времени.
	 */
	public function assignToLesson( int $groupLessonId, ?int $roomId ): void {
		$lesson = $this->groupLessons->find( $groupLessonId );
		if ( ! $lesson ) {
			throw new \InvalidArgumentException( 'Занятие не найдено.' );
		}

		if ( null !== $roomId ) {
			$room = $this->rooms->find( $roomId );
			if ( ! $room ) {
				throw new \InvalidArgumentException( 'Кабинет не найден.' );
			}
			if ( $lesson->scheduledAt ) {
				$end = $lesson->endsAt ?? ( new \DateTimeImmutable( $lesson->scheduledAt ) )
					->modify( '+60 minutes' )->format( 'Y-m-d H:i:s' );
				if ( $this->rooms->isBusy( $roomId, $lesson->scheduledAt, $end, $groupLessonId ) ) {
					throw new \InvalidArgumentException( 'Кабинет занят другим занятием в это время.' );
				}
			}
		}

		if ( $lesson->roomId !== $roomId ) {
			$this->notifyRoomChanged( $lesson, $roomId );
		}

		$this->groupLessons->setRoom( $groupLessonId, $roomId );
	}

	/**
	 * Временная замена кабинета на диапазон дат (ремонт): проставляет `room_id`
	 * всем ГРУППОВЫМ занятиям группы с датой в [$from,$to]. `NULL` = снять override
	 * (вернуть к дефолту группы). Конфликтные занятия пропускаются (в warnings).
	 *
	 * @param string $from 'Y-m-d'.
	 * @param string $to   'Y-m-d'.
	 * @return array{applied:int, skipped:int, warnings:string[]}
	 * @throws \InvalidArgumentException кабинет не найден / не для предмета группы.
	 */
	public function overrideForRange( int $groupId, ?int $roomId, string $from, string $to ): array {
		if ( null !== $roomId ) {
			$room = $this->rooms->find( $roomId );
			if ( ! $room ) {
				throw new \InvalidArgumentException( 'Кабинет не найден.' );
			}
			$group = $this->groups->findById( $groupId );
			if ( $group && ! $room->allowsSubject( (string) $group->subject_key ) ) {
				throw new \InvalidArgumentException( 'Кабинет не предназначен для предмета группы.' );
			}
		}

		$applied = 0;
		$skipped = array();
		foreach ( $this->groupLessons->listByGroup( $groupId ) as $lesson ) {
			if ( $lesson->kind->isIndividual() || ! $lesson->scheduledAt ) {
				continue;
			}
			$date = substr( $lesson->scheduledAt, 0, 10 );
			if ( $date < $from || $date > $to ) {
				continue;
			}
			if ( null !== $roomId ) {
				$end = $lesson->endsAt ?? ( new \DateTimeImmutable( $lesson->scheduledAt ) )
					->modify( '+60 minutes' )->format( 'Y-m-d H:i:s' );
				if ( $this->rooms->isBusy( $roomId, $lesson->scheduledAt, $end, $lesson->id ) ) {
					$skipped[] = $date;
					continue;
				}
			}
			if ( $lesson->roomId !== $roomId ) {
				$this->notifyRoomChanged( $lesson, $roomId );
			}

			$this->groupLessons->setRoom( $lesson->id, $roomId );
			++$applied;
		}

		$warnings = array();
		if ( $skipped ) {
			$warnings[] = sprintf( 'Пропущено (кабинет занят): %s', implode( ', ', $skipped ) );
		}
		return array( 'applied' => $applied, 'skipped' => count( $skipped ), 'warnings' => $warnings );
	}

	/**
	 * Снимает ссылки на кабинет со всех групп и занятий (перед hard-delete кабинета,
	 * т.к. реальных FK в схеме нет — иначе `room_id` повис бы на несуществующей строке).
	 */
	public function unassignFromAll( int $roomId ): void {
		$this->groups->clearRoomId( $roomId );
		$this->groupLessons->clearRoomId( $roomId );
	}

	/**
	 * Ученикам занятия + эффективному преподавателю — разовая замена кабинета
	 * (Tasks.md, блок B). Дефолт группы на год (`assignToGroup()`) уведомлений
	 * не шлёт — решено не спамить на год вперёд.
	 */
	private function notifyRoomChanged( GroupLessonDTO $lesson, ?int $newRoomId ): void {
		$recipients = $this->notifications->lessonStudentUserIds( $lesson );
		$teacherId  = $this->notifications->lessonTeacherUserId( $lesson );
		if ( null !== $teacherId ) {
			$recipients[] = $teacherId;
		}
		if ( empty( $recipients ) ) {
			return;
		}

		$payload = array(
			'group_name' => $this->notifications->groupName( $lesson->groupId ),
			'old_room'   => $this->roomLabel( $lesson->roomId, $lesson->groupId ),
			'new_room'   => $this->roomLabel( $newRoomId, $lesson->groupId ),
		);

		$this->notifications->pushFresh(
			$recipients,
			NotificationType::RoomChanged,
			"room-lesson:{$lesson->id}",
			$payload,
			PageRoutes::LessonPlayer->lessonUrl( $lesson->groupId, $lesson->id ),
			$lesson->groupId,
			'group_lesson',
			$lesson->id
		);
	}

	/** Название кабинета: явный `$roomId`, иначе дефолт группы (`NULL` = кабинет не назначен). */
	private function roomLabel( ?int $roomId, int $groupId ): string {
		if ( null !== $roomId ) {
			return $this->rooms->find( $roomId )?->name ?? '—';
		}

		$group         = $this->groups->findById( $groupId );
		$defaultRoomId = ( $group && isset( $group->room_id ) && '' !== $group->room_id ) ? (int) $group->room_id : null;

		return $defaultRoomId ? ( $this->rooms->find( $defaultRoomId )?->name ?? '—' ) : '—';
	}
}
