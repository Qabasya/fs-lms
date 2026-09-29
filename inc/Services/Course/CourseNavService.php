<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\DTO\Course\CourseDTO;
use Inc\DTO\Course\GroupLessonDTO;
use Inc\Enums\Course\LessonKind;
use Inc\Managers\Course\CourseManager;
use Inc\Managers\Course\LessonManager;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;

/**
 * Class CourseNavService
 *
 * Навигационная read-модель плеера урока (Эпик 14): оболочка (T14.2, D18) —
 * курс, «Модуль N · тема», прогресс курса, следующий урок. Дерева курса в плеере
 * больше нет (Tasks.md З4): по курсу ходят со страницы курса в кабинете.
 * Данные ученика PII-safe: имя из снапшота student_records, не из зашифрованных
 * person_documents.
 *
 * @package Inc\Services\Course
 */
class CourseNavService {

	/** Пер-request кэш карты завершённости уроков: "person:group" => [glId => bool]. */
	private array $completionCache = array();

	public function __construct(
		private readonly GroupsRepository        $groups,
		private readonly GroupLessonRepository   $groupLessons,
		private readonly CourseManager           $courses,
		private readonly LessonProgressService   $progress,
		private readonly LessonGateResolver      $gate,
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

		// Teacher-режим (Этап 2, ★): studentPersonId=0 — нет ученика, нет прогресса.
		$isTeacher = 0 === $studentPersonId;

		$record = $isTeacher ? null : $this->findRecord( $studentPersonId, $groupLesson->groupId );
		$name   = null !== $record
			? trim( $record->snapshotLastName . ' ' . $record->snapshotFirstName )
			: '';
		$grade  = (string) ( $record->snapshotGrade ?? '' );

		return array(
			'course_id'       => (int) ( $course?->id ?? 0 ),
			'course_title'    => $course?->title ?? (string) ( $group->name ?? '' ),
			'module_label'    => null !== $course
				? $this->moduleLabel( $course, (int) $groupLesson->lessonId )
				: '',
			'course_progress' => $isTeacher ? null : $this->courseProgress( $studentPersonId, $groupLesson->groupId ),
			'student_name'    => $name,
			'student_role'    => '' !== $grade
				? sprintf( '%s · %s', __( 'Ученик', 'fs-lms' ), $grade )
				: __( 'Ученик', 'fs-lms' ),
			'next_lesson'     => $this->nextLesson( $studentPersonId, $groupLesson ),
		);
	}

	/**
	 * Следующий урок программы после текущего (для «К следующему уроку» с гейтом).
	 *
	 * @return array{group_lesson_id:int, available:bool}|null NULL — текущий урок последний.
	 */
	private function nextLesson( int $studentPersonId, GroupLessonDTO $current ): ?array {
		$rows = $this->programRows( $current->groupId );
		foreach ( $rows as $i => $row ) {
			if ( $row->id !== $current->id ) {
				continue;
			}
			$next = $rows[ $i + 1 ] ?? null;
			if ( null === $next ) {
				return null;
			}

			return array(
				'group_lesson_id' => $next->id,
				// Teacher-режим (Этап 2): без ученика гейт всегда открыт.
				'available'       => 0 === $studentPersonId || $this->gate->resolveLesson( $studentPersonId, $next )->isAvailable(),
			);
		}

		return null;
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
	 *
	 * @return array{percent:int, done:int, total:int}|null NULL — в программе нет уроков.
	 */
	private function courseProgress( int $studentPersonId, int $groupId ): ?array {
		$map   = $this->completionMap( $studentPersonId, $groupId );
		$total = count( $map );
		if ( 0 === $total ) {
			return null;
		}

		$done = count( array_filter( $map ) );

		return array(
			'percent' => (int) round( $done / $total * 100 ),
			'done'    => $done,
			'total'   => $total,
		);
	}

	/**
	 * Уроки программы группы: только групповые занятия (kind=group), строки-
	 * продолжения тем (D14) исключены — это вторая дата того же урока.
	 *
	 * @return GroupLessonDTO[]
	 */
	private function programRows( int $groupId ): array {
		return array_values(
			array_filter(
				$this->groupLessons->listByGroup( $groupId ),
				static fn( GroupLessonDTO $row ): bool => LessonKind::Group === $row->kind && null === $row->continuedFromId
			)
		);
	}

	/**
	 * Карта завершённости уроков программы (glId => bool) с пер-request кэшем:
	 * нужна и прогрессу оболочки, и дереву — isLessonCompleted дорогой.
	 *
	 * @return array<int, bool>
	 */
	private function completionMap( int $studentPersonId, int $groupId ): array {
		$key = $studentPersonId . ':' . $groupId;
		if ( ! isset( $this->completionCache[ $key ] ) ) {
			$map = array();
			foreach ( $this->programRows( $groupId ) as $row ) {
				$map[ $row->id ] = $this->progress->isLessonCompleted( $studentPersonId, $row->id );
			}
			$this->completionCache[ $key ] = $map;
		}

		return $this->completionCache[ $key ];
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
