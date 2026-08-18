<?php

declare( strict_types=1 );

namespace Tests\Support;

use Inc\DTO\Course\GroupLessonDTO;
use Inc\DTO\Course\LessonDTO;
use Inc\Enums\Course\LessonKind;

/**
 * Общие фикстуры строк программы и уроков для тестов сервисов КТП.
 */
trait GroupLessonFixtures {

	private function makeLesson( string $subjectKey ): LessonDTO {
		return new LessonDTO(
			id        : 10,
			subjectKey: $subjectKey,
			topic     : 'Test lesson',
			steps     : array(),
			authorId  : 1,
			status    : 'publish',
		);
	}

	private function makeRow( int $id = 42, string $kind = 'group', ?int $roomId = null, ?int $continuedFromId = null, ?int $lessonId = 10 ): GroupLessonDTO {
		return new GroupLessonDTO(
			id              : $id,
			groupId         : 5,
			lessonId        : $lessonId,
			position        : 0,
			workIdsSnapshot : null,
			extraWorkIds    : array(),
			scheduledAt     : null,
			endsAt          : null,
			isPinned        : false,
			teacherUserId   : null,
			visibility      : 'hidden',
			openedAt        : null,
			homeworkDueAt   : null,
			allowLate       : true,
			recordingUrl    : null,
			createdByUserId : null,
			updatedByUserId : null,
			kind            : LessonKind::fromValueOrDefault( $kind ),
			roomId          : $roomId,
			continuedFromId : $continuedFromId,
		);
	}
}
