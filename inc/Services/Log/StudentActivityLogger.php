<?php

declare( strict_types=1 );

namespace Inc\Services\Log;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Log\Events\LearningEvent;
use Inc\Enums\Log\LogEvent;
use Inc\Enums\Log\TrackedPage;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\Log\LearningEventRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;

/**
 * Class StudentActivityLogger
 *
 * Открытия учеников и родителей для ленты «Активность» кабинета преподавателя: урок, запись занятия,
 * страница кабинета. Пишутся в журнал обучения ({@see LearningEventRepository}), а не в админские журналы.
 *
 * Повтор того же события того же пользователя в окне {@see self::LESSON_WINDOW_SEC} /
 * {@see self::PAGE_WINDOW_SEC} не пишется: обновление страницы или возврат на вкладку не должны
 * засорять ленту. Событие видят преподаватель группы и те, кто управляет группой
 * ({@see \Inc\Services\Course\GroupAccessGuard::canManage()}); ученикам ленты нет — `is_public = 0`.
 *
 * @package Inc\Services\Log
 */
class StudentActivityLogger {

	/** Окно дедупликации открытия урока и записи, секунд. */
	public const LESSON_WINDOW_SEC = 1800;

	/** Окно дедупликации страницы кабинета, секунд. */
	public const PAGE_WINDOW_SEC = 600;

	public function __construct(
		private readonly LogEventDispatcherInterface $dispatcher,
		private readonly LearningEventRepository     $events,
		private readonly GroupLessonRepository       $groupLessons,
		private readonly StudentRecordRepository     $studentRecords,
	) {}

	/** Открыт плеер урока занятия группы. */
	public function lessonOpened( int $userId, int $groupId, int $groupLessonId ): void {
		$this->record( LogEvent::LessonOpened, $userId, $groupId, 'group_lesson', (string) $groupLessonId, self::LESSON_WINDOW_SEC );
	}

	/** Открыта запись занятия (ссылка или встроенное видео шага «Запись занятия»). */
	public function recordingOpened( int $userId, int $groupLessonId ): bool {
		$row = $this->groupLessons->find( $groupLessonId );
		if ( null === $row ) {
			return false;
		}

		$this->record( LogEvent::RecordingOpened, $userId, $row->groupId, 'group_lesson', (string) $groupLessonId, self::LESSON_WINDOW_SEC );

		return true;
	}

	/**
	 * Открыт экран кабинета: событие пишется в каждую активную группу ученика, чтобы его видел
	 * преподаватель каждой из них.
	 */
	public function pageViewed( int $userId, int $studentPersonId, TrackedPage $page ): void {
		foreach ( $this->studentRecords->findActiveByStudent( $studentPersonId ) as $record ) {
			$this->record( LogEvent::PageViewed, $userId, $record->groupId, 'page', $page->value, self::PAGE_WINDOW_SEC );
		}
	}

	private function record( LogEvent $event, int $userId, int $groupId, string $entityType, string $entityId, int $windowSec ): void {
		if ( $userId <= 0 || $groupId <= 0 ) {
			return;
		}
		if ( $this->events->existsWithin( $event->value, $userId, $groupId, $entityType, $entityId, $windowSec ) ) {
			return;
		}

		$this->dispatcher->dispatch(
			$event,
			new LearningEvent(
				event      : $event,
				actorUserId: $userId,
				groupId    : $groupId,
				entityType : $entityType,
				entityId   : $entityId,
				isPublic   : false,
			)
		);
	}
}
