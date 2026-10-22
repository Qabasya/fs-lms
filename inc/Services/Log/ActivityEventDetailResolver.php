<?php

declare( strict_types=1 );

namespace Inc\Services\Log;

use Inc\DTO\Log\LearningEventDTO;
use Inc\Enums\Log\LogEvent;
use Inc\Enums\Log\TrackedPage;
use Inc\Managers\Course\LessonManager;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;

/**
 * Class ActivityEventDetailResolver
 *
 * Подпись «на чём» для событий ленты «Активность»: тема занятия у открытия урока и записи,
 * название экрана у открытия страницы кабинета. Остальные события подписи не имеют.
 * Тема читается один раз на занятие — в ленте одно занятие повторяется у многих учеников.
 *
 * @package Inc\Services\Log
 */
class ActivityEventDetailResolver {

	/** @var array<int, string> group_lesson_id → тема. */
	private array $topics = array();

	public function __construct(
		private readonly GroupLessonRepository $groupLessons,
		private readonly LessonManager         $lessons,
	) {}

	public function detail( LearningEventDTO $event ): string {
		return match ( $event->action ) {
			LogEvent::LessonOpened->value, LogEvent::RecordingOpened->value => $this->lessonTopic( (int) $event->entityId ),
			LogEvent::PageViewed->value => TrackedPage::tryFrom( (string) $event->entityId )?->label() ?? '',
			default => '',
		};
	}

	private function lessonTopic( int $groupLessonId ): string {
		if ( $groupLessonId <= 0 ) {
			return '';
		}
		if ( isset( $this->topics[ $groupLessonId ] ) ) {
			return $this->topics[ $groupLessonId ];
		}

		$row   = $this->groupLessons->find( $groupLessonId );
		$topic = $row && $row->lessonId ? ( $this->lessons->get( $row->lessonId )?->topic ?? '' ) : '';
		if ( '' === $topic && $row ) {
			$topic = (string) ( $row->label ?? '' );
		}

		return $this->topics[ $groupLessonId ] = $topic;
	}
}
