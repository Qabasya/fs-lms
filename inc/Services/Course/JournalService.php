<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\Managers\Course\LessonManager;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\RoomRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;

/**
 * Class JournalService
 *
 * Сборка read-модели журнала группы: ростер × (занятия + работы).
 *
 * По модели D4: в ячейках занятий — посещаемость (+/−), в столбцах работ —
 * СЫРЫЕ результаты (решённые задачи / баллы экзамена) из GradebookService.
 * НИКАКИХ 5-балльных оценок и среднего балла.
 *
 * @package Inc\Services\Course
 */
class JournalService {

	public function __construct(
		private readonly StudentRecordRepository $records,
		private readonly GroupLessonRepository   $groupLessons,
		private readonly LessonManager           $lessons,
		private readonly AttendanceService       $attendance,
		private readonly GradebookService        $gradebook,
		private readonly RoomRepository          $rooms,
		private readonly GroupsRepository        $groups,
	) {}

	/**
	 * Модель журнала (T10.5): в ячейке (ученик×занятие) — посещаемость + результаты
	 * работ ЭТОГО занятия по типам (`GradeBadge`). Отдельных столбцов-работ нет.
	 *
	 * @return array{
	 *   students: array<int,array{person_id:int,name:string}>,
	 *   lessons: array<int,array{group_lesson_id:int,date:string,topic:string,room:string}>,
	 *   attendance: array<int,array<int,bool>>,
	 *   cell_works: array<int,array<int,array<int,array{badge:string,value:string,display:string}>>>,
	 *   types: string[]
	 * }
	 */
	public function forGroup( int $groupId ): array {
		// Ростер (строки).
		$students = array();
		foreach ( $this->records->findActiveByGroupId( $groupId ) as $rec ) {
			$students[] = array(
				'person_id' => $rec->studentPersonId,
				'name'      => trim( $rec->snapshotLastName . ' ' . $rec->snapshotFirstName ),
			);
		}

		// Эффективный кабинет (T11.2): кабинет занятия ?? основной кабинет группы.
		$group       = $this->groups->findById( $groupId );
		$groupRoomId = ( $group && ! empty( $group->room_id ) ) ? (int) $group->room_id : 0;
		$roomNames   = array();
		foreach ( $this->rooms->findAll() as $r ) {
			$roomNames[ $r->id ] = $r->name;
		}

		// Столбцы-занятия (только датированные).
		$lessons = array();
		foreach ( $this->groupLessons->listByGroup( $groupId ) as $row ) {
			// Индивидуальные (на одного ученика) не образуют столбец группового журнала.
			if ( ! $row->scheduledAt || 'individual' === $row->kind ) {
				continue;
			}
			$lesson    = $row->lessonId ? $this->lessons->get( $row->lessonId ) : null;
			$effRoomId = ! empty( $row->roomId ) ? (int) $row->roomId : $groupRoomId;
			$lessons[] = array(
				'group_lesson_id' => $row->id,
				'date'            => substr( $row->scheduledAt, 0, 10 ),
				'topic'           => $lesson?->topic ?? ( $row->label ?? '' ),
				'room'            => ( $effRoomId && isset( $roomNames[ $effRoomId ] ) ) ? $roomNames[ $effRoomId ] : '',
			);
		}

		// Посещаемость (+/−).
		$attendance = $this->attendance->matrixForGroup( $groupId );

		// Результаты работ, разложенные по занятию (T10.5): cell_works[glid][pid] = [{badge,value}].
		$cellWorks = array();
		$typesSet  = array();
		foreach ( $this->gradebook->forGroup( $groupId ) as $entry ) {
			if ( null === $entry->groupLessonId || null === $entry->badge ) {
				continue;
			}
			$badge = $entry->badge->badge();
			$cellWorks[ $entry->groupLessonId ][ $entry->studentPersonId ][] = array(
				'badge'   => $badge,
				'value'   => $entry->displayValue(),
				'display' => $entry->displayType,
			);
			$typesSet[ $badge ] = true;
		}

		// Порядок типов для фильтров: только присутствующие в группе.
		$order = array( 'СР', 'ПР', 'ДЗ', 'КР', 'ЭКЗ' );
		$types = array_values( array_filter( $order, static fn( $b ) => isset( $typesSet[ $b ] ) ) );

		return array(
			'students'   => $students,
			'lessons'    => $lessons,
			'attendance' => $attendance,
			'cell_works' => (object) $cellWorks,
			'types'      => $types,
		);
	}
}
