<?php

declare( strict_types=1 );

namespace Unit\DTO\Course;

use Inc\DTO\Course\SubmissionDTO;
use Inc\Enums\SubmissionStatus;
use Inc\Enums\WorkType;
use PHPUnit\Framework\TestCase;

class SubmissionDTOTest extends TestCase {

	private function make( ?string $submittedAt, ?string $dueAt ): SubmissionDTO {
		return new SubmissionDTO(
			id               : 1,
			studentPersonId  : 10,
			groupLessonId    : 5,
			workId           : 3,
			workType         : WorkType::Practice,
			taskId           : null,
			answerText       : 'answer',
			attachmentId     : null,
			dueAt            : $dueAt,
			status           : SubmissionStatus::Submitted,
			score            : null,
			maxScore         : null,
			feedback         : null,
			gradedByUserId   : null,
			submittedAt      : $submittedAt,
			gradedAt         : null,
			createdAt        : '2024-01-01 00:00:00',
			updatedAt        : '2024-01-01 00:00:00',
		);
	}

	public function test_isLate_false_when_submitted_before_due(): void {
		$dto = $this->make( '2024-03-01 10:00:00', '2024-03-01 23:59:00' );
		$this->assertFalse( $dto->isLate() );
	}

	public function test_isLate_true_when_submitted_after_due(): void {
		$dto = $this->make( '2024-03-02 00:01:00', '2024-03-01 23:59:00' );
		$this->assertTrue( $dto->isLate() );
	}

	public function test_isLate_false_when_no_due_at(): void {
		$dto = $this->make( '2024-03-02 00:01:00', null );
		$this->assertFalse( $dto->isLate() );
	}

	public function test_isLate_false_when_no_submitted_at(): void {
		$dto = $this->make( null, '2024-03-01 23:59:00' );
		$this->assertFalse( $dto->isLate() );
	}

	public function test_isLate_false_when_submitted_exactly_at_due(): void {
		// equal timestamps: not late (strict greater-than)
		$dto = $this->make( '2024-03-01 23:59:00', '2024-03-01 23:59:00' );
		$this->assertFalse( $dto->isLate() );
	}
}
