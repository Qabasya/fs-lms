<?php

declare( strict_types=1 );

namespace Unit\DTO\Course;

use Inc\DTO\Course\GroupLessonDTO;
use PHPUnit\Framework\TestCase;

class GroupLessonDTOTest extends TestCase {

	private function make( array $workDeadlines = [], ?string $homeworkDueAt = null ): GroupLessonDTO {
		return new GroupLessonDTO(
			id              : 5,
			groupId         : 1,
			lessonId        : 10,
			position        : 0,
			workIdsSnapshot : null,
			extraWorkIds    : [],
			scheduledAt     : null,
			endsAt          : null,
			isPinned        : false,
			teacherUserId   : null,
			visibility      : 'open',
			openedAt        : null,
			homeworkDueAt   : $homeworkDueAt,
			allowLate       : true,
			recordingUrl    : null,
			createdByUserId : null,
			updatedByUserId : null,
			workDeadlines   : $workDeadlines,
		);
	}

	/* ── deadlineForWork (T12.2, D13) ─────────────────────────────────────── */

	public function test_deadline_for_work_prefers_per_work_override(): void {
		$row = $this->make( [ 3 => '2026-06-01 12:00:00' ], '2026-01-01 00:00:00' );
		self::assertSame( '2026-06-01 12:00:00', $row->deadlineForWork( 3 ) );
	}

	public function test_deadline_for_work_falls_back_to_legacy_homework_due_at(): void {
		$row = $this->make( [], '2026-01-01 00:00:00' );
		self::assertSame( '2026-01-01 00:00:00', $row->deadlineForWork( 3 ) );
	}

	public function test_deadline_for_work_falls_back_for_work_without_own_override(): void {
		// work_deadlines has an entry for a DIFFERENT work_id — 3 still falls back to legacy.
		$row = $this->make( [ 999 => '2026-06-01 12:00:00' ], '2026-01-01 00:00:00' );
		self::assertSame( '2026-01-01 00:00:00', $row->deadlineForWork( 3 ) );
	}

	public function test_deadline_for_work_null_when_no_deadline_anywhere(): void {
		$row = $this->make( [], null );
		self::assertNull( $row->deadlineForWork( 3 ) );
	}

	/* ── fromArray JSON deserialization ──────────────────────────────────── */

	private function baseRow(): array {
		return array(
			'id' => 5, 'group_id' => 1, 'lesson_id' => 10, 'position' => 0,
			'is_pinned' => 0, 'visibility' => 'open',
		);
	}

	public function test_from_array_decodes_work_deadlines_json(): void {
		$row = array_merge( $this->baseRow(), array(
			'work_deadlines' => '{"3":"2026-06-01 12:00:00","7":"2026-06-05 09:00:00"}',
		) );
		$dto = GroupLessonDTO::fromArray( $row );

		self::assertSame(
			array( 3 => '2026-06-01 12:00:00', 7 => '2026-06-05 09:00:00' ),
			$dto->workDeadlines
		);
	}

	public function test_from_array_null_work_deadlines_yields_empty_array(): void {
		$dto = GroupLessonDTO::fromArray( $this->baseRow() );
		self::assertSame( array(), $dto->workDeadlines );
	}

	public function test_from_array_malformed_json_yields_empty_array(): void {
		$row = array_merge( $this->baseRow(), array( 'work_deadlines' => 'not-json' ) );
		$dto = GroupLessonDTO::fromArray( $row );
		self::assertSame( array(), $dto->workDeadlines );
	}

	public function test_from_array_drops_non_string_deadline_values(): void {
		$row = array_merge( $this->baseRow(), array(
			'work_deadlines' => '{"3":"2026-06-01 12:00:00","7":null,"9":123}',
		) );
		$dto = GroupLessonDTO::fromArray( $row );
		self::assertSame( array( 3 => '2026-06-01 12:00:00' ), $dto->workDeadlines );
	}
}
