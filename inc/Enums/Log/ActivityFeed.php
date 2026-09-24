<?php

declare( strict_types=1 );

namespace Inc\Enums\Log;

/**
 * Вкладка экрана «Активность» кабинета преподавателя — срез журнала обучения
 * ({@see LogChannel::LearningEvents}).
 *
 * - `Events` — работа учеников: сдачи работ, попытки контрольных и их оценки;
 * - `Course` — действия с курсом группы: программа, расписание, открытие тем.
 */
enum ActivityFeed: string {

	case Events = 'events';
	case Course = 'course';

	public static function fromValueOrDefault( string $value ): self {
		return self::tryFrom( $value ) ?? self::Events;
	}

	/** @return string[] Значения {@see LogEvent}, попадающие во вкладку. */
	public function actions(): array {
		$events = match ( $this ) {
			self::Events => array(
				LogEvent::SubmissionMade,
				LogEvent::SubmissionGraded,
				LogEvent::SubmissionReturned,
				LogEvent::AttemptStarted,
				LogEvent::AttemptSubmitted,
				LogEvent::AttemptGraded,
				LogEvent::AttemptExpired,
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
		};

		return array_map( static fn( LogEvent $e ): string => $e->value, $events );
	}
}
