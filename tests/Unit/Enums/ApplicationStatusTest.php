<?php

declare( strict_types=1 );

namespace Unit\Enums;

use Inc\Enums\Enrollment\ApplicationStatus;
use PHPUnit\Framework\TestCase;

/**
 * Истекает только заявка, которая ждёт родителя: заполненная родителем ждёт сотрудника,
 * и прежний переход ready_for_review → expired выкосил на проде все непроверенные заявки.
 */
class ApplicationStatusTest extends TestCase {

	public function test_pending_parent_can_expire(): void {
		$this->assertTrue( ApplicationStatus::PendingParent->canTransitionTo( ApplicationStatus::Expired ) );
	}

	public function test_ready_for_review_never_expires(): void {
		$this->assertFalse( ApplicationStatus::ReadyForReview->canTransitionTo( ApplicationStatus::Expired ) );
		$this->assertTrue( ApplicationStatus::ReadyForReview->canTransitionTo( ApplicationStatus::Enrolling ) );
		$this->assertTrue( ApplicationStatus::ReadyForReview->canTransitionTo( ApplicationStatus::Trash ) );
	}
}
