<?php

declare( strict_types=1 );

namespace Unit\Services\Enrollment;

use DomainException;
use Inc\Services\Enrollment\FamilyEmailPolicy;
use PHPUnit\Framework\TestCase;

class FamilyEmailPolicyTest extends TestCase {

	public function test_rejects_same_email_ignoring_case_and_spaces(): void {
		$this->expectException( DomainException::class );

		( new FamilyEmailPolicy() )->assertDistinct( 'Family@Mail.ru', ' family@mail.ru ' );
	}

	public function test_accepts_different_emails_and_empty_student_email(): void {
		$policy = new FamilyEmailPolicy();

		$policy->assertDistinct( 'kid@mail.ru', 'mom@mail.ru' );
		// Ученик без email — сравнивать не с чем (родитель заполнит свой).
		$policy->assertDistinct( '', '' );

		$this->addToAssertionCount( 2 );
	}
}
