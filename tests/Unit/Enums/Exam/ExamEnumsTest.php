<?php

declare( strict_types=1 );

namespace Unit\Enums\Exam;

use Inc\Enums\Exam\ExamAudience;
use Inc\Enums\Exam\ExamDirection;
use Inc\Enums\Exam\ExamEventStatus;
use Inc\Enums\Exam\ExamOutboxEvent;
use Inc\Enums\Exam\ExamPaymentState;
use Inc\Enums\Exam\ExamProgress;
use Inc\Enums\Exam\ExamRegistrationStatus;
use Inc\Enums\Exam\ExamSessionStatus;
use Inc\Enums\Exam\ExamTokenPurpose;
use Inc\Enums\Exam\GuestApplicationState;
use Inc\Enums\Exam\ManualResolutionKind;
use Inc\Enums\Settings\TableName;
use PHPUnit\Framework\TestCase;

/** Энумы экзаменов: подписи для интерфейса, состояния брони, имена таблиц. */
class ExamEnumsTest extends TestCase {

	/** @var list<class-string<\BackedEnum>> */
	private const ENUMS = array(
		ExamAudience::class, ExamDirection::class, ExamEventStatus::class, ExamOutboxEvent::class, ExamPaymentState::class, ExamProgress::class,
		ExamRegistrationStatus::class, ExamSessionStatus::class, ExamTokenPurpose::class, GuestApplicationState::class, ManualResolutionKind::class,
	);

	/** @return iterable<string, array{\BackedEnum}> */
	public static function everyCase(): iterable {
		foreach ( self::ENUMS as $enum ) {
			foreach ( $enum::cases() as $case ) {
				yield $enum . '::' . $case->name => array( $case );
			}
		}
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'everyCase' )]
	public function test_every_case_has_non_empty_label( \BackedEnum $case ): void {
		self::assertTrue( method_exists( $case, 'label' ), get_class( $case ) . ' без label()' );
		self::assertNotSame( '', trim( (string) $case->label() ), $case->name );
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'everyCase' )]
	public function test_labels_have_no_gendered_forms( \BackedEnum $case ): void {
		// Подписи нейтральны по роду: «Запись подтверждена», «Работа сдана» — а не «записан», «сдал», «пришёл», «не явился».
		foreach ( array( 'записан ', 'сдал', 'пришёл', 'пришел', 'не явился', 'не явилась' ) as $gendered ) {
			self::assertStringNotContainsString( $gendered, mb_strtolower( (string) $case->label() ), $case->name );
		}
	}

	public function test_hold_states_hold_seat(): void {
		self::assertTrue( GuestApplicationState::Hold->holdsSeat() );
		self::assertTrue( GuestApplicationState::AwaitingPayment->holdsSeat() );
		self::assertTrue( GuestApplicationState::PaymentPending->holdsSeat() );

		foreach ( array( GuestApplicationState::Confirmed, GuestApplicationState::ExpiredUnpaid, GuestApplicationState::Failed, GuestApplicationState::PaidNeedsResolution, GuestApplicationState::Cancelled, GuestApplicationState::Missed ) as $state ) {
			self::assertFalse( $state->holdsSeat(), $state->name );
		}
	}

	public function test_terminal_states(): void {
		foreach ( array( GuestApplicationState::Confirmed, GuestApplicationState::ExpiredUnpaid, GuestApplicationState::Failed, GuestApplicationState::Cancelled, GuestApplicationState::Missed ) as $state ) {
			self::assertTrue( $state->isTerminal(), $state->name );
		}
		foreach ( array( GuestApplicationState::Hold, GuestApplicationState::AwaitingPayment, GuestApplicationState::PaymentPending, GuestApplicationState::PaidNeedsResolution ) as $state ) {
			self::assertFalse( $state->isTerminal(), $state->name );
		}
	}

	public function test_a_state_never_both_holds_a_seat_and_is_terminal(): void {
		foreach ( GuestApplicationState::cases() as $state ) {
			self::assertFalse( $state->holdsSeat() && $state->isTerminal(), $state->name );
		}
	}

	public function test_event_status_editable_and_accepts_registration(): void {
		self::assertTrue( ExamEventStatus::Draft->isEditable() );
		self::assertTrue( ExamEventStatus::Published->isEditable() );
		self::assertFalse( ExamEventStatus::Completed->isEditable() );
		self::assertFalse( ExamEventStatus::Cancelled->isEditable() );

		self::assertTrue( ExamEventStatus::Published->acceptsRegistration() );
		self::assertFalse( ExamEventStatus::Draft->acceptsRegistration() );
		self::assertFalse( ExamEventStatus::Cancelled->acceptsRegistration() );
	}

	public function test_table_names_have_plugin_prefix(): void {
		$exam = array_filter( TableName::cases(), static fn ( TableName $t ): bool => str_starts_with( $t->name, 'Exam' ) );

		self::assertCount( 15, $exam );
		foreach ( $exam as $table ) {
			self::assertStringStartsWith( 'fs_lms_exam_', $table->value, $table->name );
		}
	}

	public function test_enum_values_are_unique_within_each_enum(): void {
		foreach ( self::ENUMS as $enum ) {
			$values = array_map( static fn ( \BackedEnum $c ) => $c->value, $enum::cases() );
			self::assertSame( $values, array_values( array_unique( $values ) ), $enum );
		}
	}
}
