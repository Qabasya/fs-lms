<?php

declare( strict_types=1 );

namespace Tests\Support;

use Inc\DTO\Course\GroupLessonDTO;

/**
 * Общая фикстура строки программы для тестов AJAX-коллбэков КТП.
 */
trait ProgramRowFixtures {

	/**
	 * @param array<int, string> $workDeadlines Персональные дедлайны работ занятия
	 */
	private function programRow( array $workDeadlines = array() ): GroupLessonDTO {
		return new GroupLessonDTO(
			id: 42, groupId: 5, lessonId: 1, position: 0, workIdsSnapshot: null, extraWorkIds: array(),
			scheduledAt: null, endsAt: null, isPinned: false, teacherUserId: null, visibility: 'open',
			openedAt: null, homeworkDueAt: null, allowLate: true, recordingUrl: null,
			createdByUserId: null, updatedByUserId: null, workDeadlines: $workDeadlines,
		);
	}
}
