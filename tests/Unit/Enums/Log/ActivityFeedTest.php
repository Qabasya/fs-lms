<?php

declare( strict_types=1 );

namespace Unit\Enums\Log;

use Inc\Enums\Log\ActivityFeed;
use Inc\Enums\Log\LogEvent;
use PHPUnit\Framework\TestCase;

class ActivityFeedTest extends TestCase {

	public function test_unknown_value_falls_back_to_events(): void {
		self::assertSame( ActivityFeed::Events, ActivityFeed::fromValueOrDefault( '' ) );
		self::assertSame( ActivityFeed::Course, ActivityFeed::fromValueOrDefault( 'course' ) );
	}

	public function test_grades_belong_to_events_feed(): void {
		self::assertContains( LogEvent::SubmissionGraded->value, ActivityFeed::Events->actions() );
		self::assertContains( LogEvent::AttemptGraded->value, ActivityFeed::Events->actions() );
		self::assertContains( LogEvent::ScheduleChanged->value, ActivityFeed::Course->actions() );
	}

	/** Новое событие журнала обучения обязано попасть ровно в одну вкладку — иначе его не увидят. */
	public function test_every_learning_event_is_in_exactly_one_feed(): void {
		$all = array_merge( ActivityFeed::Events->actions(), ActivityFeed::Course->actions() );

		foreach ( LogEvent::cases() as $event ) {
			if ( ! str_starts_with( $event->value, 'learning.' ) ) {
				continue;
			}
			self::assertSame( 1, count( array_keys( $all, $event->value, true ) ), $event->value );
		}
	}
}
