<?php

declare( strict_types=1 );

namespace Unit\Enums\Profile;

use Inc\Enums\Profile\NotificationType;
use PHPUnit\Framework\TestCase;

class NotificationTypeTest extends TestCase {

	/** @return list<NotificationType> */
	private function examTypes(): array {
		return array_values( array_filter( NotificationType::cases(), static fn ( NotificationType $t ): bool => str_starts_with( $t->value, 'exam_' ) ) );
	}

	public function test_every_case_has_title_and_tone(): void {
		foreach ( NotificationType::cases() as $type ) {
			self::assertNotSame( '', $type->title(), $type->name );
			self::assertContains( $type->tone(), array( 'ok', 'warn', 'err', 'info' ), $type->name );
		}
	}

	public function test_there_are_eighteen_exam_types(): void {
		self::assertCount( 18, $this->examTypes() );
	}

	public function test_exam_titles_have_no_gendered_forms(): void {
		foreach ( $this->examTypes() as $type ) {
			self::assertDoesNotMatchRegularExpression( '/\b(сдал|сдала|записан|записана|пришёл|пришла)\b/iu', $type->title(), $type->name );
		}
	}

	public function test_case_values_fit_column_length(): void {
		foreach ( NotificationType::cases() as $type ) {
			self::assertLessThanOrEqual( 40, strlen( $type->value ), $type->name );
		}
	}
}
