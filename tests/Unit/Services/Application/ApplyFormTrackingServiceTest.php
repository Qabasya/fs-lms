<?php

declare(strict_types=1);

namespace Unit\Services\Application;

use Inc\DTO\Application\ApplyTrackInputDTO;
use Inc\Enums\Auth\AuthAction;
use Inc\Enums\Auth\AuthResult;
use Inc\Enums\Auth\CaptchaFailure;
use Inc\Enums\Auth\LoginFailReason;
use Inc\Enums\Enrollment\ApplyFormEvent;
use Inc\Services\Application\ApplyFormTrackingService;
use Inc\Services\Log\AuthLogWriter;
use PHPUnit\Framework\TestCase;

class ApplyFormTrackingServiceTest extends TestCase {

	private AuthLogWriter&\PHPUnit\Framework\MockObject\MockObject $authLog;
	private ApplyFormTrackingService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->authLog = $this->createMock( AuthLogWriter::class );
		$this->service = new ApplyFormTrackingService( $this->authLog );
	}

	private function input( ApplyFormEvent $event, array $over = array() ): ApplyTrackInputDTO {
		return new ApplyTrackInputDTO(
			event:   $event,
			visit:   $over['visit'] ?? 'abc12345',
			stage:   $over['stage'] ?? 'form',
			fields:  $over['fields'] ?? array(),
			filled:  $over['filled'] ?? 3,
			total:   $over['total'] ?? 11,
			seconds: $over['seconds'] ?? 75,
			submits: $over['submits'] ?? 0,
			message: $over['message'] ?? '',
			captcha: $over['captcha'] ?? null,
		);
	}

	public function test_started_is_logged_as_neutral_event_with_visit(): void {
		$this->authLog->expects( $this->once() )->method( 'recordEvent' )->with(
			AuthAction::ApplyStarted,
			AuthResult::Success,
			null,
			$this->callback( static fn( array $d ): bool => 'abc12345' === $d['visit'] && 'apply' === $d['form'] && str_contains( $d['note'], '1 мин 15 с' ) )
		);

		$this->service->track( $this->input( ApplyFormEvent::Started ) );
	}

	public function test_invalid_lists_field_labels_never_values(): void {
		$this->authLog->expects( $this->once() )->method( 'recordEvent' )->with(
			AuthAction::ApplyInvalid,
			AuthResult::Failure,
			null,
			$this->callback( static fn( array $d ): bool => str_contains( $d['note'], 'ошибки в полях: email, телефон' ) && str_contains( $d['note'], 'заполнено 3 из 11 полей' ) )
		);

		$this->service->track( $this->input( ApplyFormEvent::Invalid, array( 'fields' => array( 'email', 'phone', 'unknown_field' ) ) ) );
	}

	public function test_captcha_failure_carries_reason_and_notes_fallback(): void {
		$this->authLog->expects( $this->once() )->method( 'recordEvent' )->with(
			AuthAction::ApplyCaptchaFailed,
			AuthResult::Failure,
			LoginFailReason::CaptchaNotLoaded,
			$this->callback( static fn( array $d ): bool => str_contains( $d['note'], 'VPN' ) && str_contains( $d['note'], 'без токена' ) )
		);

		$this->service->track( $this->input( ApplyFormEvent::CaptchaFailed, array( 'captcha' => CaptchaFailure::NotLoaded ) ) );
	}

	public function test_dismissed_captcha_notes_that_form_was_not_sent(): void {
		$this->authLog->expects( $this->once() )->method( 'recordEvent' )->with(
			AuthAction::ApplyCaptchaFailed,
			AuthResult::Failure,
			LoginFailReason::CaptchaDismissed,
			$this->callback( static fn( array $d ): bool => str_contains( $d['note'], 'форма не отправлена' ) )
		);

		$this->service->track( $this->input( ApplyFormEvent::CaptchaFailed, array( 'captcha' => CaptchaFailure::Dismissed ) ) );
	}

	public function test_submit_failed_records_server_answer_and_stage(): void {
		$this->authLog->expects( $this->once() )->method( 'recordEvent' )->with(
			AuthAction::ApplySubmitFailed,
			AuthResult::Failure,
			null,
			$this->callback( static fn( array $d ): bool => 'Неверный код' === $d['failure_reason'] && str_contains( $d['note'], 'ответ: «Неверный код»' ) && str_contains( $d['note'], 'этап: код из письма' ) )
		);

		$this->service->track( $this->input( ApplyFormEvent::SubmitFailed, array( 'message' => 'Неверный код', 'stage' => 'otp', 'submits' => 2 ) ) );
	}

	public function test_guard_failure_has_exact_reason_in_visible_column(): void {
		$this->authLog->expects( $this->once() )->method( 'recordEvent' )->with(
			AuthAction::ApplySubmitFailed,
			AuthResult::Failure,
			null,
			$this->callback( static fn( array $d ): bool => 'abc12345' === $d['visit'] && str_contains( $d['failure_reason'], 'устарела' ) )
		);

		$this->service->recordGuardFailure( 'token_expired', 'abc12345' );
	}

	public function test_clip_message_truncates_long_text(): void {
		self::assertSame( 200, mb_strlen( $this->service->clipMessage( str_repeat( 'я', 500 ) ) ) );
	}
}
