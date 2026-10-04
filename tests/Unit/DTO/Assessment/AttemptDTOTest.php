<?php

declare( strict_types=1 );

namespace Unit\DTO\Assessment;

use Inc\DTO\Assessment\AttemptDTO;
use PHPUnit\Framework\TestCase;

/** Попытка: поля экзамена (участие, запись) и отсутствие ученика у гостевой попытки. */
class AttemptDTOTest extends TestCase {

	/** @param array<string, mixed> $override @return array<string, mixed> */
	private function row( array $override = array() ): array {
		return array_merge( array(
			'id' => '5', 'assessment_id' => '500', 'student_person_id' => '11', 'group_id' => null, 'attempt_number' => '1',
			'started_at' => '2026-03-12 10:00:00', 'deadline_at' => '2026-03-12 13:55:00', 'status' => 'in_progress',
			'created_at' => '2026-03-12 10:00:00', 'updated_at' => '2026-03-12 10:00:00',
		), $override );
	}

	public function test_from_array_reads_exam_fields(): void {
		$attempt = AttemptDTO::fromArray( $this->row( array( 'exam_participation_id' => '7', 'exam_registration_id' => '20' ) ) );

		self::assertSame( 7, $attempt->examParticipationId );
		self::assertSame( 20, $attempt->examRegistrationId );
	}

	public function test_from_array_allows_null_student_for_guest(): void {
		$attempt = AttemptDTO::fromArray( $this->row( array( 'student_person_id' => null, 'exam_participation_id' => '7' ) ) );

		self::assertNull( $attempt->studentPersonId );
		self::assertTrue( $attempt->isExam() );
	}

	public function test_is_exam(): void {
		self::assertTrue( AttemptDTO::fromArray( $this->row( array( 'exam_participation_id' => '7' ) ) )->isExam() );
		self::assertFalse( AttemptDTO::fromArray( $this->row() )->isExam(), 'Попытка работы курса — не экзамен.' );
	}

	public function test_exam_fields_survive_hiding_totals(): void {
		$attempt = AttemptDTO::fromArray( $this->row( array( 'exam_participation_id' => '7', 'exam_registration_id' => '20', 'total_score' => '17', 'max_score' => '29' ) ) );

		$hidden = $attempt->withoutTotals();

		self::assertNull( $hidden->totalScore );
		self::assertSame( 7, $hidden->examParticipationId );
		self::assertSame( 20, $hidden->examRegistrationId );
	}
}
