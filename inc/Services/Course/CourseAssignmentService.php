<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Course\GroupLessonInputDTO;
use Inc\DTO\Log\Events\LearningEvent;
use Inc\Enums\Course\AssignmentPolicy;
use Inc\Enums\Log\LogEvent;
use Inc\Managers\Course\CourseManager;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;

class CourseAssignmentService {

	public function __construct(
		private readonly CourseManager               $courseManager,
		private readonly GroupsRepository            $groups,
		private readonly GroupLessonRepository       $groupLessons,
		private readonly LogEventDispatcherInterface $dispatcher,
	) {}

	/**
	 * Курсы предмета группы для пикера назначения в КТП (Эпик 11 T11.1).
	 *
	 * @return array<int, array{id:int, title:string}>
	 */
	public function coursesForGroup( int $groupId ): array {
		$group = $this->groups->findById( $groupId );
		if ( ! $group ) {
			return array();
		}
		return array_map(
			static fn( $course ): array => array( 'id' => $course->id, 'title' => $course->title ),
			$this->courseManager->getBankBySubject( (string) $group->subject_key )
		);
	}

	/**
	 * Снапшотит уроки курса в программу группы.
	 *
	 * @param AssignmentPolicy $policy Append — дописать; Replace — заменить (удаляет текущие строки).
	 * @return int  Число добавленных строк.
	 */
	public function assign( int $groupId, int $courseId, int $actorUserId, AssignmentPolicy $policy = AssignmentPolicy::Append ): int {
		$group  = $this->groups->findById( $groupId );
		$course = $this->courseManager->get( $courseId );

		if ( ! $group || ! $course ) {
			throw new \InvalidArgumentException( 'Группа или курс не найдены.' );
		}
		if ( $course->subjectKey !== $group->subject_key ) {
			throw new \InvalidArgumentException( 'Курс принадлежит другому предмету.' );
		}

		if ( AssignmentPolicy::Replace === $policy ) {
			$this->groupLessons->deleteAllByGroup( $groupId );
		}

		$position = $this->groupLessons->nextPosition( $groupId );
		$added    = 0;
		foreach ( $course->lessonIds() as $lessonId ) {
			$this->groupLessons->add( new GroupLessonInputDTO(
				groupId         : $groupId,
				lessonId        : $lessonId,
				position        : $position,
				createdByUserId : $actorUserId,
			) );
			$position++;
			$added++;
		}

		$this->groups->update( $groupId, array( 'course_id' => $courseId ) );

		$this->dispatcher->dispatch(
			LogEvent::CourseAssigned,
			new LearningEvent(
				event       : LogEvent::CourseAssigned,
				actorUserId : $actorUserId,
				subjectKey  : $course->subjectKey,
				groupId     : $groupId,
				entityType  : 'course',
				entityId    : (string) $courseId,
			)
		);

		return $added;
	}

	/**
	 * НБ-7: дописывает НОВЫЕ уроки курса в КТП уже назначенных групп.
	 *
	 * Курс — снапшот-источник: строки group_lessons создаются в момент assign().
	 * Урок, добавленный в курс позже, сам в КТП не попадает. Метод по каждой
	 * НЕзаблокированной группе с этим курсом дописывает недостающие lesson_id в
	 * конец программы (без scheduled_at — расстановку сделает reflow/«Распределить»),
	 * дедуплицируя по уже присутствующим урокам. Опубликованные (заблокированные)
	 * КТП не трогаем. Вызывается после добавления/дублирования урока в конструкторе.
	 *
	 * @return int Число добавленных строк group_lessons.
	 */
	public function syncCourseLessons( int $courseId, int $actorUserId ): int {
		$course = $this->courseManager->get( $courseId );
		if ( ! $course ) {
			return 0;
		}

		$courseLessonIds = $course->lessonIds();
		if ( empty( $courseLessonIds ) ) {
			return 0;
		}

		$added = 0;
		foreach ( $this->groups->findByCourse( $courseId ) as $group ) {
			// Опубликованную (заблокированную) КТП не трогаем.
			if ( ! empty( $group->program_locked_at ) ) {
				continue;
			}

			$groupId  = (int) $group->id;
			$existing = array();
			foreach ( $this->groupLessons->listByGroup( $groupId ) as $row ) {
				if ( null !== $row->lessonId ) {
					$existing[ (int) $row->lessonId ] = true;
				}
			}

			$position = $this->groupLessons->nextPosition( $groupId );
			foreach ( $courseLessonIds as $lessonId ) {
				if ( isset( $existing[ (int) $lessonId ] ) ) {
					continue;
				}
				$this->groupLessons->add( new GroupLessonInputDTO(
					groupId         : $groupId,
					lessonId        : $lessonId,
					position        : $position,
					createdByUserId : $actorUserId,
				) );
				$existing[ (int) $lessonId ] = true;
				$position++;
				$added++;
			}
		}

		return $added;
	}
}
