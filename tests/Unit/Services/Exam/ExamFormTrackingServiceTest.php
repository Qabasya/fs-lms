<?php

declare( strict_types=1 );

namespace Tests\Unit\Services\Exam;

use Inc\DTO\Exam\ExamGuestApplicationDTO;
use Inc\DTO\Exam\ExamSourceDTO;
use Inc\Enums\Auth\AuthAction;
use Inc\Enums\Auth\AuthResult;
use Inc\Services\Exam\ExamFormTrackingService;
use Inc\Services\Log\AuthLogWriter;
use PHPUnit\Framework\TestCase;

/**
 * Журнал гостевой формы: четыре события в общем журнале «Аутентификация», без введённых значений.
 */
class ExamFormTrackingServiceTest extends TestCase {

	/** @var list<array{AuthAction, AuthResult, array<string, mixed>}> */
	private array $recorded = array();
	private ExamFormTrackingService $service;

	protected function setUp(): void {
		parent::setUp();
		$writer = $this->createMock( AuthLogWriter::class );
		$writer->method( 'recordEvent' )->willReturnCallback( function ( AuthAction $a, AuthResult $r, $reason = null, array $details = array() ): void {
			$this->recorded[] = array( $a, $r, $details );
		} );
		$this->service = new ExamFormTrackingService( $writer );
	}

	private function source(): ExamSourceDTO {
		return ExamSourceDTO::fromArray( array(
			'id' => '14', 'event_id' => '3', 'school_name' => 'Школа 5', 'school_name_normalized' => 'школа 5', 'grade' => '11', 'teacher_name' => 'Т', 'label' => 'l',
			'is_active' => '1', 'key_generation' => '1', 'created_by_user_id' => '10', 'version' => '1', 'created_at' => '2026-03-01 00:00:00', 'updated_at' => '2026-03-01 00:00:00',
		) );
	}

	public function test_four_events_use_the_shared_auth_journal(): void {
		$application = ExamGuestApplicationDTO::fromArray( array(
			'id' => '9', 'event_id' => '3', 'session_id' => '7', 'source_id' => '14', 'identity_hash' => 'h', 'state' => 'awaiting_payment', 'is_held' => '1',
			'request_key' => 'k', 'version' => '1', 'created_at' => '2026-03-01 00:00:00', 'updated_at' => '2026-03-01 00:00:00',
		) );

		$this->service->opened( $this->source() );
		$this->service->invalid( $this->source(), 'phone', 'Укажите телефон: 11 цифр.' );
		$this->service->holdCreated( $this->source(), $application );
		$this->service->limited( $this->source(), 'Слишком много заявок с этого адреса.' );

		self::assertSame(
			array( AuthAction::ExamFormOpened, AuthAction::ExamFormInvalid, AuthAction::ExamHoldCreated, AuthAction::ExamFormLimit ),
			array_column( $this->recorded, 0 )
		);
		self::assertSame( array( AuthResult::Success, AuthResult::Failure, AuthResult::Success, AuthResult::Failure ), array_column( $this->recorded, 1 ) );
	}

	public function test_details_carry_ids_and_field_name_but_no_personal_values(): void {
		$this->service->invalid( $this->source(), 'phone', 'Укажите телефон: 11 цифр.' );

		$details = $this->recorded[0][2];
		self::assertSame( 'exam_signup', $details['form'] );
		self::assertSame( 14, $details['source'] );
		self::assertSame( 'поле: phone', $details['note'] );
		foreach ( array( 'phone', 'last_name', 'first_name', 'messenger', 'k', 'key', 'cookie' ) as $forbidden ) {
			self::assertArrayNotHasKey( $forbidden, $details );
		}
	}

	public function test_long_message_is_clipped(): void {
		$this->service->limited( $this->source(), str_repeat( 'я', 500 ) );

		self::assertSame( 200, mb_strlen( $this->recorded[0][2]['failure_reason'] ) );
	}

	public function test_new_actions_have_journal_labels(): void {
		foreach ( array( AuthAction::ExamFormOpened, AuthAction::ExamFormInvalid, AuthAction::ExamHoldCreated, AuthAction::ExamFormLimit ) as $action ) {
			self::assertStringStartsWith( 'Экзамен:', $action->label() );
		}
	}
}
