<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\Managers\Course\LessonManager;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
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
	) {}

	/**
	 * @return array{
	 *   students: array<int,array{person_id:int,name:string}>,
	 *   lessons: array<int,array{group_lesson_id:int,date:string,topic:string}>,
	 *   attendance: array<int,array<int,bool>>,
	 *   works: array<int,array{key:string,label:string,type:string}>,
	 *   grades: array<string,array<int,array{value:string,display:string}>>
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

		// Столбцы-занятия (только датированные).
		$lessons = array();
		foreach ( $this->groupLessons->listByGroup( $groupId ) as $row ) {
			if ( ! $row->scheduledAt ) {
				continue;
			}
			$lesson    = $row->lessonId ? $this->lessons->get( $row->lessonId ) : null;
			$lessons[] = array(
				'group_lesson_id' => $row->id,
				'date'            => substr( $row->scheduledAt, 0, 10 ),
				'topic'           => $lesson?->topic ?? ( $row->label ?? '' ),
			);
		}

		// Посещаемость (+/−).
		$attendance = $this->attendance->matrixForGroup( $groupId );

		// Столбцы-работы + сырые результаты (без оценок/среднего).
		$works  = array();
		$grades = array();
		$seen   = array();
		foreach ( $this->gradebook->forGroup( $groupId ) as $entry ) {
			$key = $entry->sourceType . ':' . $entry->sourceId;
			if ( ! isset( $seen[ $key ] ) ) {
				$seen[ $key ] = true;
				$works[]      = array(
					'key'   => $key,
					'label' => $entry->title,
					'type'  => $entry->category,
				);
			}
			$grades[ $key ][ $entry->studentPersonId ] = array(
				'value'   => $entry->displayValue(),
				'display' => $entry->displayType,
			);
		}

		return array(
			'students'   => $students,
			'lessons'    => $lessons,
			'attendance' => $attendance,
			'works'      => $works,
			'grades'     => $grades,
		);
	}
}
