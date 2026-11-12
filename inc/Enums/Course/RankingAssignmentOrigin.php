<?php

declare( strict_types=1 );

namespace Inc\Enums\Course;

enum RankingAssignmentOrigin: string {
	case Lesson = 'lesson';
	case Extra = 'extra';

	/** Возвращает null, если источник нельзя однозначно восстановить. */
	public static function resolve( int $workId, array $lessonIds, array $extraIds, ?array $existing = null ): ?self {
		$recorded = self::tryFrom( (string) ( $existing['origin'] ?? '' ) );
		if ( $recorded ) {
			return $recorded;
		}
		$lesson = in_array( $workId, $lessonIds, true );
		$extra = in_array( $workId, $extraIds, true );
		return $lesson === $extra ? null : ( $lesson ? self::Lesson : self::Extra );
	}
}
