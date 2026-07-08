<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\Enums\Course\AccessMode;
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
		private readonly LessonProgressService   $progress,
	) {}

	/**
	 * Модель журнала (T10.5): в ячейке (ученик×занятие) — посещаемость + результаты
	 * работ ЭТОГО занятия по типам (`GradeBadge`). Отдельных столбцов-работ нет.
	 *
	 * @return array{
	 *   students: array<int,array{person_id:int,name:string}>,
	 *   lessons: array<int,array{group_lesson_id:int,date:string,topic:string,room:string,is_continuation:bool}>,
	 *   attendance: array<int,array<int,bool>>,
	 *   cell_works: array<int,array<int,array<int,array{badge:string,value:string,display:string,overdue:bool}>>>,
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

		// Эпик 15: открытая группа — занятия без дат, столбцы формируются по порядку
		// программы (посещаемость в таком журнале не ведётся).
		$isOpen = $group && AccessMode::Open === AccessMode::fromValueOrDefault( (string) ( $group->access_mode ?? '' ) );

		// Столбцы-занятия (в обычной группе — только датированные).
		$lessons = array();
		foreach ( $this->groupLessons->listByGroup( $groupId ) as $row ) {
			// Индивидуальные (на одного ученика) не образуют столбец группового журнала.
			if ( 'individual' === $row->kind ) {
				continue;
			}
			if ( ! $row->scheduledAt && ! $isOpen ) {
				continue;
			}
			$lesson    = $row->lessonId ? $this->lessons->get( $row->lessonId ) : null;
			$effRoomId = ! empty( $row->roomId ) ? (int) $row->roomId : $groupRoomId;
			$lessons[] = array(
				'group_lesson_id' => $row->id,
				'date'            => $row->scheduledAt ? substr( $row->scheduledAt, 0, 10 ) : '',
				'topic'           => $lesson?->topic ?? ( $row->label ?? '' ),
				'room'            => ( $effRoomId && isset( $roomNames[ $effRoomId ] ) ) ? $roomNames[ $effRoomId ] : '',
				// T12.6 (D14): продолжение темы — второй столбец той же темы, помечается «(прод.)».
				'is_continuation' => null !== $row->continuedFromId,
			);
		}

		// Посещаемость (+/−). В открытой группе учителя нет — вручную никто не отмечает,
		// «+» синтезируется из фактического прохождения ВСЕХ шагов урока (D-C продолжение).
		$attendance = $isOpen
			? $this->openCompletionMatrix( $lessons, $students )
			: $this->attendance->matrixForGroup( $groupId );

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
				// T12.2 (D13): постоянная метка «Просрочено» — сдано после дедлайна работы.
				'overdue' => $entry->isLate,
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
			'open'       => $isOpen,
		);
	}

	/**
	 * Матрица «+» для открытой группы: студент × занятие, true — только если
	 * ВСЕ шаги урока пройдены (LessonProgressService::isLessonCompleted). Незавершённые
	 * ячейки намеренно отсутствуют в матрице (а не false) — это «ещё не пройдено», а
	 * не «отсутствовал», такого статуса в открытой группе не существует.
	 *
	 * @param array<int,array{group_lesson_id:int}> $lessons
	 * @param array<int,array{person_id:int}>       $students
	 *
	 * @return array<int,array<int,bool>>
	 */
	private function openCompletionMatrix( array $lessons, array $students ): array {
		$matrix = array();
		foreach ( $lessons as $lesson ) {
			$glid = (int) $lesson['group_lesson_id'];
			foreach ( $students as $student ) {
				$pid = (int) $student['person_id'];
				if ( $this->progress->isLessonCompleted( $pid, $glid ) ) {
					$matrix[ $glid ][ $pid ] = true;
				}
			}
		}

		return $matrix;
	}
}
