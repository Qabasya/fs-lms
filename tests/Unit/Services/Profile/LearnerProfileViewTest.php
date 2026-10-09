<?php

declare( strict_types=1 );

namespace Unit\Services\Profile;

use Inc\DTO\Profile\ProfileContext;
use Inc\Enums\Access\UserRole;
use Inc\Services\Profile\LearnerProfileView;
use PHPUnit\Framework\TestCase;

/**
 * Витрина учащегося: пункт «Мои экзамены» виден всегда — ученику и родителю, без условий на наличие проведений.
 */
class LearnerProfileViewTest extends TestCase {

	/** @return array<string, array{0: ProfileContext}> */
	public static function contexts(): array {
		return array(
			'ученик'  => array( new ProfileContext( 1, 5, UserRole::FSStudent, 5, false, array() ) ),
			'родитель' => array( new ProfileContext( 1, 3, UserRole::FSParent, 7, true, array( array( 'personId' => 7, 'name' => 'A' ) ) ) ),
			'родитель без детей' => array( new ProfileContext( 1, 3, UserRole::FSParent, null, true, array() ) ),
		);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'contexts' )]
	public function test_exams_item_always_present_for_student_and_parent( ProfileContext $context ): void {
		$built = ( new LearnerProfileView() )->build( $context );

		$keys = array_column( $built['nav'], 'key' );
		self::assertContains( 'learner-exams', $keys );
		self::assertContains( 'learner-exams', $built['screens'] );
		self::assertSame( 'Мои экзамены', array_column( $built['nav'], 'label', 'key' )['learner-exams'] );
	}

	public function test_exams_item_is_last_in_menu(): void {
		$keys = array_column( ( new LearnerProfileView() )->build( new ProfileContext( 1, 5, UserRole::FSStudent, 5, false, array() ) )['nav'], 'key' );

		self::assertSame( 'learner-exams', end( $keys ) );
	}
}