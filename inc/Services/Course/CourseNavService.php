<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\DTO\Course\CourseDTO;
use Inc\DTO\Course\GroupLessonDTO;
use Inc\Managers\Course\CourseManager;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;

/**
 * Class CourseNavService
 *
 * Навигационная read-модель плеера курса (Эпик 14): оболочка (T14.2, D18) —
 * курс группы, модуль текущего урока, сквозной прогресс курса и данные ученика
 * (PII-safe: имя из снапшота student_records, не из зашифрованных person_documents).
 *
 * @package Inc\Services\Course
 */
class CourseNavService {

	public function __construct(
		private readonly GroupsRepository        $groups,
		private readonly GroupLessonRepository   $groupLessons,
		private readonly CourseManager           $courses,
		private readonly LessonProgressService   $progress,
		private readonly StudentRecordRepository $records,
	) {}

	/**
	 * Оболочка плеера: заголовок курса, «Модуль N · тема», прогресс курса, ученик.
	 *
	 * @return array{
	 *     course_title: string,
	 *     module_label: string,
	 *     course_progress: array{percent:int, done:int, total:int}|null,
	 *     student_name: string,
	 *     student_role: string
	 * }
	 */
	public function shell( int $studentPersonId, GroupLessonDTO $groupLesson ): array {
		$group  = $this->groups->findById( $groupLesson->groupId );
		$course = ( null !== $group && ! empty( $group->course_id ) )
			? $this->courses->get( (int) $group->course_id )
			: null;

		$record = $this->findRecord( $studentPersonId, $groupLesson->groupId );
		$name   = null !== $record
			? trim( $record->snapshotLastName . ' ' . $record->snapshotFirstName )
			: '';
		$grade  = (string) ( $record->snapshotGrade ?? '' );

		return array(
			'course_title'    => $course?->title ?? (string) ( $group->name ?? '' ),
			'module_label'    => null !== $course
				? $this->moduleLabel( $course, (int) $groupLesson->lessonId )
				: '',
			'course_progress' => $this->courseProgress( $studentPersonId, $groupLesson->groupId ),
			'student_name'    => $name,
			'student_role'    => '' !== $grade
				? sprintf( '%s · %s', __( 'Ученик', 'fs-lms' ), $grade )
				: __( 'Ученик', 'fs-lms' ),
		);
	}

	/**
	 * «Модуль N · тема» для модуля, содержащего урок; пустая строка, если урок
	 * не найден в модулях курса (например, добавлен в программу вручную).
	 */
	private function moduleLabel( CourseDTO $course, int $lessonId ): string {
		foreach ( array_values( $course->modules ) as $i => $module ) {
			if ( in_array( $lessonId, $module->lessonIds, true ) ) {
				return sprintf(
					/* translators: 1: module number, 2: module title */
					__( 'Модуль %1$d · %2$s', 'fs-lms' ),
					$i + 1,
					$module->title
				);
			}
		}

		return '';
	}

	/**
	 * Сквозной прогресс курса: пройденные/все уроки программы группы.
	 * Считаются только групповые занятия (kind=group); строки-продолжения тем
	 * (D14) исключаются — это вторая дата того же урока, не отдельный урок.
	 *
	 * @return array{percent:int, done:int, total:int}|null NULL — в программе нет уроков.
	 */
	private function courseProgress( int $studentPersonId, int $groupId ): ?array {
		$rows = array_filter(
			$this->groupLessons->listByGroup( $groupId ),
			static fn( GroupLessonDTO $row ): bool => 'group' === $row->kind && null === $row->continuedFromId
		);

		$total = count( $rows );
		if ( 0 === $total ) {
			return null;
		}

		$done = 0;
		foreach ( $rows as $row ) {
			if ( $this->progress->isLessonCompleted( $studentPersonId, $row->id ) ) {
				++$done;
			}
		}

		return array(
			'percent' => (int) round( $done / $total * 100 ),
			'done'    => $done,
			'total'   => $total,
		);
	}

	/**
	 * Запись ученика в группе (для снапшота имени/класса): активная приоритетнее,
	 * но подходит и архивная — в плеер пускают всех, кто когда-либо был участником.
	 */
	private function findRecord( int $studentPersonId, int $groupId ): ?object {
		$match = null;
		foreach ( $this->records->findByStudent( $studentPersonId ) as $record ) {
			if ( $record->groupId !== $groupId ) {
				continue;
			}
			if ( $record->isActive() ) {
				return $record;
			}
			$match ??= $record;
		}

		return $match;
	}
}
