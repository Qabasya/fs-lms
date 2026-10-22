<?php

declare( strict_types=1 );

namespace Inc\Enums\Log;

/**
 * Лента экрана «Активность» кабинета преподавателя — срез журнала обучения
 * ({@see LogChannel::LearningEvents}).
 *
 * - `Events` — всё, что делают ученики и родители: работы, контрольные, открытия уроков, записей, страниц;
 * - `Works`  — только сдачи работ, попытки контрольных и их оценки;
 * - `Opens`  — только открытия: урок, запись занятия, страница кабинета;
 * - `Course` — действия с курсом группы: программа, расписание, открытие тем.
 *
 * `Works` и `Opens` — фильтры внутри вкладки «Лента событий».
 */
enum ActivityFeed: string {

	case Events = 'events';
	case Works  = 'works';
	case Opens  = 'opens';
	case Course = 'course';

	public static function fromValueOrDefault( string $value ): self {
		return self::tryFrom( $value ) ?? self::Events;
	}

	/** @return string[] Значения {@see LogEvent}, попадающие в ленту. */
	public function actions(): array {
		$events = match ( $this ) {
			self::Events => array_merge( self::Works->events(), self::Opens->events() ),
			self::Works, self::Opens, self::Course => $this->events(),
		};

		return array_map( static fn( LogEvent $e ): string => $e->value, $events );
	}

	/** @return LogEvent[] События собственно этой ленты (без объединения). */
	private function events(): array {
		return match ( $this ) {
			self::Works => array(
				LogEvent::SubmissionMade,
				LogEvent::SubmissionGraded,
				LogEvent::SubmissionReturned,
				LogEvent::AttemptStarted,
				LogEvent::AttemptSubmitted,
				LogEvent::AttemptGraded,
				LogEvent::AttemptExpired,
			),
			self::Opens => array(
				LogEvent::LessonOpened,
				LogEvent::RecordingOpened,
				LogEvent::PageViewed,
			),
			self::Course => array(
				LogEvent::CourseAssigned,
				LogEvent::LessonAddedToProgram,
				LogEvent::LessonRemovedFromProgram,
				LogEvent::ScheduleChanged,
				LogEvent::ExtraWorksChanged,
				LogEvent::LessonPublished,
				LogEvent::LessonHidden,
			),
			self::Events => array(),
		};
	}
}
