<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\Contracts\ClockInterface;
use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Course\GroupLessonInputDTO;
use Inc\DTO\Log\Events\LearningEvent;
use Inc\Enums\Course\AccessMode;
use Inc\Enums\Course\AssignmentPolicy;
use Inc\Enums\Course\LessonVisibility;
use Inc\Enums\Log\LogEvent;
use Inc\Managers\Course\CourseManager;
use Inc\Managers\Course\LessonManager;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;

class CourseAssignmentService {

	public function __construct(
		private readonly CourseManager               $courseManager,
		private readonly GroupsRepository            $groups,
		private readonly GroupLessonRepository       $groupLessons,
		private readonly LogEventDispatcherInterface $dispatcher,
		private readonly LessonManager               $lessonManager,
		private readonly ClockInterface              $clock,
		private readonly OpenCourseValidator         $openCourseValidator,
		private readonly GroupLessonUsageGuard       $usageGuard,
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

		$openMode = $this->isOpenGroup( $group );

		if ( AssignmentPolicy::Replace === $policy ) {
			$this->groupLessons->deleteAllByGroup( $groupId );
		}

		$position = $this->groupLessons->nextPosition( $groupId );
		$added    = 0;
		foreach ( $course->lessonIds() as $lessonId ) {
			$this->groupLessons->add( $this->programRow( $groupId, $lessonId, $position, $actorUserId, $openMode ) );
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
	 * Предупреждения самопроверки для открытой группы (D-C) — назначение курса без
	 * автопроверяемого контента больше НЕ блокируется (ручная проверка в открытой
	 * группе не критична — там просто некому её сделать), но админ/учитель должен
	 * увидеть, в каких уроках есть такие задачи. Пустой массив — группа не открытая
	 * или проблем нет.
	 *
	 * @return string[]
	 */
	public function warningsFor( int $groupId, int $courseId ): array {
		$group  = $this->groups->findById( $groupId );
		$course = $this->courseManager->get( $courseId );
		if ( ! $group || ! $course || ! $this->isOpenGroup( $group ) ) {
			return array();
		}

		return $this->openCourseValidator->problems( $course );
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
			$openMode = $this->isOpenGroup( $group );
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
				$this->groupLessons->add( $this->programRow( $groupId, $lessonId, $position, $actorUserId, $openMode ) );
				$existing[ (int) $lessonId ] = true;
				$position++;
				$added++;
			}
		}

		return $added;
	}

	/**
	 * D17.3: полная синхронизация КТП групп с составом курса — дописать недостающие
	 * уроки ({@see syncCourseLessons()}) И удалить осиротевшие строки доставки для
	 * уроков, которых больше нет в курсе. Удаляем ТОЛЬКО в незаблокированных группах
	 * и ТОЛЬКО строки без вовлечённости ученика (guard) — иначе за строкой стоят
	 * данные журнала (реально проведённый урок), и её нельзя трогать.
	 *
	 * Вызывается при сохранении структуры курса (урок убрали из курса) — чинит
	 * ложный блок удаления и фантомный урок в КТП/журнале.
	 *
	 * @return array{added: int, removed: int}
	 */
	public function reconcileCourseLessons( int $courseId, int $actorUserId ): array {
		$added   = $this->syncCourseLessons( $courseId, $actorUserId );
		$removed = $this->removeOrphanCourseLessons( $courseId );

		return array( 'added' => $added, 'removed' => $removed );
	}

	/**
	 * Удаляет осиротевшие строки доставки: уроки, которых больше нет в курсе, из
	 * КТП незаблокированных групп этого курса — только строки без вовлечённости.
	 *
	 * @return int Число удалённых строк.
	 */
	private function removeOrphanCourseLessons( int $courseId ): int {
		$course = $this->courseManager->get( $courseId );
		if ( ! $course ) {
			return 0;
		}

		$courseLessonIds = array_flip( array_map( 'intval', $course->lessonIds() ) );

		$removed = 0;
		foreach ( $this->groups->findByCourse( $courseId ) as $group ) {
			// Опубликованную (заблокированную) КТП не трогаем — доставка заморожена.
			if ( ! empty( $group->program_locked_at ) ) {
				continue;
			}

			foreach ( $this->groupLessons->listByGroup( (int) $group->id ) as $row ) {
				$lessonId = (int) ( $row->lessonId ?? 0 );
				if ( $lessonId <= 0 || 'individual' === $row->kind ) {
					continue; // индивидуальные/безурочные строки — не из курса.
				}
				if ( isset( $courseLessonIds[ $lessonId ] ) ) {
					continue; // урок всё ещё в курсе — это реальная доставка.
				}
				if ( ! $this->usageGuard->isSafeToRemove( (int) $row->id ) ) {
					continue; // за строкой есть данные журнала — не трогаем.
				}
				if ( $this->groupLessons->remove( (int) $row->id ) ) {
					$removed++;
				}
			}
		}

		return $removed;
	}

	private function isOpenGroup( object $group ): bool {
		return AccessMode::Open === AccessMode::fromValueOrDefault( (string) ( $group->access_mode ?? '' ) );
	}

	/**
	 * Строка программы для снапшота урока.
	 *
	 * Открытая группа (Эпик 15): строка создаётся сразу опубликованной —
	 * visibility=open + copy-on-publish снапшот работ + opened_at, без даты
	 * занятия (scheduled_at=NULL гейт трактует как «доступно сразу»).
	 */
	private function programRow( int $groupId, int $lessonId, int $position, int $actorUserId, bool $openMode ): GroupLessonInputDTO {
		if ( ! $openMode ) {
			return new GroupLessonInputDTO(
				groupId         : $groupId,
				lessonId        : $lessonId,
				position        : $position,
				createdByUserId : $actorUserId,
			);
		}

		$lesson = $this->lessonManager->get( $lessonId );

		return new GroupLessonInputDTO(
			groupId         : $groupId,
			lessonId        : $lessonId,
			position        : $position,
			workIdsSnapshot : $lesson?->workIds() ?? array(),
			visibility      : LessonVisibility::Open->value,
			openedAt        : $this->clock->now(),
			createdByUserId : $actorUserId,
		);
	}
}
