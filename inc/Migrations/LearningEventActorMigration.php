<?php

declare( strict_types=1 );

namespace Inc\Migrations;

use Inc\Enums\Access\UserRole;
use Inc\Enums\Log\LogEvent;
use Inc\Enums\Settings\TableName;

/**
 * Class LearningEventActorMigration
 *
 * События ученика (сдача работы, попытки экзамена) писали в `actor_user_id` ID персоны
 * вместо ID WP-пользователя, и лента «Активность» подписывала их чужими именами —
 * тех, чей WP ID совпал с ID персоны. Здесь ID персоны заменяется её `wp_user_id`
 * (персона без учётки — NULL), роль — ролью ученика.
 *
 * Data-миграция, version-gated собственной опцией (паттерн
 * {@see SubmissionScoreBackfillMigration}).
 *
 * @package Inc\Migrations
 */
class LearningEventActorMigration {

	private const VERSION_OPTION = 'fs_lms_learning_event_actor_version';
	private const VERSION        = '1';

	private const STUDENT_EVENTS = array(
		LogEvent::SubmissionMade,
		LogEvent::AttemptStarted,
		LogEvent::AttemptSubmitted,
		LogEvent::AttemptGraded,
		LogEvent::AttemptExpired,
	);

	public function run(): void {
		if ( get_option( self::VERSION_OPTION ) === self::VERSION ) {
			return;
		}

		$this->remap();

		update_option( self::VERSION_OPTION, self::VERSION, false );
	}

	private function remap(): void {
		global $wpdb;

		$actions      = array_map( static fn( LogEvent $e ): string => $e->value, self::STUDENT_EVENTS );
		$placeholders = implode( ', ', array_fill( 0, count( $actions ), '%s' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE %i e
				 LEFT JOIN %i p ON p.id = e.actor_user_id
				 SET e.actor_user_id = p.wp_user_id, e.actor_role = %s
				 WHERE e.actor_user_id IS NOT NULL AND e.action IN ( $placeholders )",
				TableName::LearningEvents->prefixed(),
				TableName::Persons->prefixed(),
				UserRole::FSStudent->value,
				...$actions
			)
		);
		// phpcs:enable
	}
}
