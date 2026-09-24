<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\Contracts\ClockInterface;
use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Course\GroupLessonDTO;
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
use Inc\Services\Group\ScheduleEventPublisher;
use Inc\Services\Group\ScheduleReflowService;

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
		private readonly ScheduleReflowService       $schedule,
		private readonly ScheduleEventPublisher      $events,
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
	 * @param AssignmentPolicy $policy Append — дописать; Replace — заменить (удаляет текущие строки без данных учеников).
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
			$this->removeSafeRows( $groupId );
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
	 * НБ-7: доставляет НОВЫЕ уроки курса в КТП уже назначенных групп — курс
	 * живой, КТП всегда повторяет его состав.
	 *
	 * Урок встаёт в программу туда же, где стоит в курсе: сразу за последней
	 * строкой предыдущего урока курса (с учётом продолжений темы), а не в конец.
	 * Если план уже распределён, урок сразу получает дату, а непроведённый хвост
	 * сдвигается на одно занятие ({@see ScheduleReflowService::placeInserted()}):
	 * урок, дописанный в конец курса, встаёт следующим занятием после последнего.
	 *
	 * Опубликованные (заблокированные) КТП синхронизируются тоже: публикация
	 * запрещает ручные правки плана, но не доставку изменений курса (T1.8).
	 * Открытая группа получает только опубликованные уроки — строка там сразу
	 * открыта ученикам; черновик доедет, когда его опубликуют.
	 *
	 * @return int Число добавленных строк group_lessons.
	 */
	public function syncCourseLessons( int $courseId, int $actorUserId ): int {
		$course = $this->courseManager->get( $courseId );
		if ( ! $course ) {
			return 0;
		}

		$courseLessonIds = array_map( 'intval', $course->lessonIds() );
		if ( empty( $courseLessonIds ) ) {
			return 0;
		}

		$added = 0;
		foreach ( $this->groups->findByCourse( $courseId ) as $group ) {
			$groupId  = (int) $group->id;
			$openMode = $this->isOpenGroup( $group );
			$rows     = $this->programRows( $groupId );

			foreach ( $courseLessonIds as $index => $lessonId ) {
				if ( $this->rowsOfLesson( $rows, $lessonId ) ) {
					continue;
				}
				if ( $openMode && ! $this->lessonManager->isPublished( $lessonId ) ) {
					continue;
				}

				$position = $this->insertPosition( $groupId, $rows, $courseLessonIds, $index );
				$this->groupLessons->shiftPositions( $groupId, $position );
				$rowId = $this->groupLessons->add( $this->programRow( $groupId, $lessonId, $position, $actorUserId, $openMode ) );

				$this->events->lessonAdded( $groupId, $lessonId, $course->subjectKey, $actorUserId );
				if ( ! $openMode ) {
					$this->schedule->placeInserted( $rowId, $actorUserId );
				}

				$rows = $this->programRows( $groupId );
				$added++;
			}
		}

		return $added;
	}

	/**
	 * Позиция вставки урока курса в программу: за последней строкой ближайшего
	 * предыдущего урока курса, уже стоящего в программе; нет такого — перед
	 * первой строкой ближайшего следующего; нет и его — в конец.
	 *
	 * @param GroupLessonDTO[] $rows            Групповые строки программы
	 * @param int[]            $courseLessonIds Уроки курса по порядку
	 */
	private function insertPosition( int $groupId, array $rows, array $courseLessonIds, int $index ): int {
		for ( $i = $index - 1; $i >= 0; $i-- ) {
			$prev = $this->rowsOfLesson( $rows, $courseLessonIds[ $i ] );
			if ( $prev ) {
				return max( array_map( static fn( GroupLessonDTO $r ): int => $r->position, $prev ) ) + 1;
			}
		}

		$count = count( $courseLessonIds );
		for ( $i = $index + 1; $i < $count; $i++ ) {
			$next = $this->rowsOfLesson( $rows, $courseLessonIds[ $i ] );
			if ( $next ) {
				return min( array_map( static fn( GroupLessonDTO $r ): int => $r->position, $next ) );
			}
		}

		return $this->groupLessons->nextPosition( $groupId );
	}

	/**
	 * Групповые строки программы (индивидуальные занятия к курсу не относятся,
	 * даже если к ним привязан урок курса).
	 *
	 * @return GroupLessonDTO[]
	 */
	private function programRows( int $groupId ): array {
		return array_values( array_filter(
			$this->groupLessons->listByGroup( $groupId ),
			static fn( GroupLessonDTO $r ): bool => ! $r->kind->isIndividual()
		) );
	}

	/**
	 * @param GroupLessonDTO[] $rows
	 *
	 * @return GroupLessonDTO[]
	 */
	private function rowsOfLesson( array $rows, int $lessonId ): array {
		return array_values( array_filter(
			$rows,
			static fn( GroupLessonDTO $r ): bool => (int) $r->lessonId === $lessonId
		) );
	}

	/**
	 * D17.3: полная синхронизация КТП групп с составом курса — дописать недостающие
	 * уроки ({@see syncCourseLessons()}) И удалить осиротевшие строки доставки для
	 * уроков, которых больше нет в курсе. Удаляем ТОЛЬКО строки без вовлечённости
	 * ученика (guard) — иначе за строкой стоят данные журнала (реально проведённый
	 * урок), и её нельзя трогать.
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
	 * КТП групп этого курса (включая опубликованные) — только строки без
	 * вовлечённости: за строкой с данными журнала стоит проведённый урок.
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
			foreach ( $this->groupLessons->listByGroup( (int) $group->id ) as $row ) {
				$lessonId = (int) ( $row->lessonId ?? 0 );
				if ( $lessonId <= 0 || $row->kind->isIndividual() ) {
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

	/**
	 * Замена курса убирает из программы только строки без данных учеников:
	 * занятия с посещаемостью, прогрессом, сдачами и попытками остаются в КТП
	 * и журнале — это отчётность, а не план. Индивидуальные занятия к курсу не
	 * относятся и не трогаются.
	 */
	private function removeSafeRows( int $groupId ): void {
		foreach ( $this->programRows( $groupId ) as $row ) {
			if ( $this->usageGuard->isSafeToRemove( $row->id ) ) {
				$this->groupLessons->remove( $row->id );
			}
		}
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
