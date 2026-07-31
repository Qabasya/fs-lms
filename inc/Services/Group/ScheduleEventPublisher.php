<?php

declare( strict_types=1 );

namespace Inc\Services\Group;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Log\Events\LearningEvent;
use Inc\Enums\Log\LogEvent;

/**
 * Class ScheduleEventPublisher
 *
 * Единая точка публикации событий обучения, которые порождает работа с КТП:
 * изменение расписания и состав программы группы.
 *
 * @package Inc\Services\Group
 *
 * Сервисы КТП ({@see ProgramCompositionService}, {@see ScheduleReflowService},
 * {@see IndividualLessonService}) описывают, ЧТО произошло; как это ложится
 * в {@see LearningEvent} — знает только этот класс.
 */
readonly class ScheduleEventPublisher {

	/**
	 * @param LogEventDispatcherInterface $dispatcher Шина событий логирования
	 */
	public function __construct(
		private LogEventDispatcherInterface $dispatcher,
	) {}

	/**
	 * Расписание группы изменилось целиком (реордер, reflow, публикация КТП).
	 *
	 * @param int $groupId     ID группы
	 * @param int $actorUserId Автор изменения
	 *
	 * @return void
	 */
	public function groupChanged( int $groupId, int $actorUserId ): void {
		$this->dispatcher->dispatch(
			LogEvent::ScheduleChanged,
			new LearningEvent(
				event      : LogEvent::ScheduleChanged,
				actorUserId: $actorUserId,
				groupId    : $groupId,
				entityType : 'group',
				entityId   : (string) $groupId,
				isPublic   : false,
			)
		);
	}

	/**
	 * Изменилась конкретная строка программы (дата, пин, кабинет, ученик).
	 *
	 * @param int $groupId       ID группы
	 * @param int $groupLessonId ID строки программы
	 * @param int $actorUserId   Автор изменения
	 *
	 * @return void
	 */
	public function lessonChanged( int $groupId, int $groupLessonId, int $actorUserId ): void {
		$this->dispatcher->dispatch(
			LogEvent::ScheduleChanged,
			new LearningEvent(
				event      : LogEvent::ScheduleChanged,
				actorUserId: $actorUserId,
				groupId    : $groupId,
				entityType : 'group_lesson',
				entityId   : (string) $groupLessonId,
				isPublic   : false,
			)
		);
	}

	/**
	 * Урок добавлен в программу группы (вручную, дублированием, продолжением).
	 *
	 * @param int         $groupId     ID группы
	 * @param int|null    $lessonId    ID урока банка
	 * @param string|null $subjectKey  Предмет урока
	 * @param int         $actorUserId Автор изменения
	 *
	 * @return void
	 */
	public function lessonAdded( int $groupId, ?int $lessonId, ?string $subjectKey, int $actorUserId ): void {
		$this->dispatcher->dispatch(
			LogEvent::LessonAddedToProgram,
			new LearningEvent(
				event      : LogEvent::LessonAddedToProgram,
				actorUserId: $actorUserId,
				subjectKey : $subjectKey,
				groupId    : $groupId,
				entityType : 'lesson',
				entityId   : (string) $lessonId,
			)
		);
	}

	/**
	 * Урок убран из программы группы.
	 *
	 * @param int         $groupId     ID группы
	 * @param int|null    $lessonId    ID урока банка
	 * @param string|null $subjectKey  Предмет урока
	 * @param int         $actorUserId Автор изменения
	 *
	 * @return void
	 */
	public function lessonRemoved( int $groupId, ?int $lessonId, ?string $subjectKey, int $actorUserId ): void {
		$this->dispatcher->dispatch(
			LogEvent::LessonRemovedFromProgram,
			new LearningEvent(
				event      : LogEvent::LessonRemovedFromProgram,
				actorUserId: $actorUserId,
				groupId    : $groupId,
				subjectKey : $subjectKey,
				entityType : 'lesson',
				entityId   : (string) $lessonId,
			)
		);
	}
}
