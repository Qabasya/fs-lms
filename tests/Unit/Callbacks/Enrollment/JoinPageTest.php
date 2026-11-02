<?php

declare( strict_types=1 );

namespace Unit\Callbacks\Enrollment;

use Inc\Callbacks\Enrollment\ApplicationCallbacks;
use Inc\DTO\Application\ApplicationDTO;
use Inc\Enums\Enrollment\ApplicationStatus;
use Inc\Repositories\WPDBRepositories\ApplicationRepository;
use Inc\Services\Application\JoinCodeService;
use Inc\Services\Security\PiiCryptoService;
use PHPUnit\Framework\TestCase;

class JoinPageTest extends TestCase {
	protected function tearDown(): void {
		unset( $GLOBALS['_fs_test_query_vars'] );
		parent::tearDown();
	}

	private function callbacks( ?ApplicationDTO $application ): ApplicationCallbacks {
		$repository = $this->createStub( ApplicationRepository::class );
		$repository->method( 'findByJoinCodeHash' )->willReturn( $application );
		$codes = $this->createStub( JoinCodeService::class );
		$codes->method( 'isValidFormat' )->willReturn( true );
		$codes->method( 'hash' )->willReturn( 'hash' );
		$codes->method( 'isExpired' )->willReturnCallback( static fn( ?string $at ): bool => 'expired' === $at );
		$crypto = $this->createMock( PiiCryptoService::class );
		$crypto->expects( self::never() )->method( 'decrypt' );
		$overrides = array( ApplicationRepository::class => $repository, JoinCodeService::class => $codes, PiiCryptoService::class => $crypto );
		$args = array();
		foreach ( ( new \ReflectionMethod( ApplicationCallbacks::class, '__construct' ) )->getParameters() as $parameter ) {
			$type = $parameter->getType()->getName();
			$args[] = $overrides[ $type ] ?? $this->createStub( $type );
		}
		set_query_var( 'fs_lms_join_code', 'JOIN-EXPIRED' );
		return new ApplicationCallbacks( ...$args );
	}

	private function application( ApplicationStatus $status, ?string $linkExpiry = null, ?string $expiry = null ): ApplicationDTO {
		return ApplicationDTO::fromArray( array(
			'id' => 1, 'status' => $status->value, 'join_code_expires_at' => $linkExpiry, 'expires_at' => $expiry,
			'created_at' => '', 'updated_at' => '', 'student_data_enc' => 'private',
		) );
	}

	public function test_link_expiry_explains_how_to_get_new_link(): void {
		self::assertFalse( $this->callbacks( $this->application( ApplicationStatus::PendingParent, 'expired' ) )->prepareJoinPage() );
		self::assertStringContainsString( 'Срок действия ссылки истёк', get_query_var( 'fs_lms_link_message' ) );
		self::assertStringContainsString( 'администратору', get_query_var( 'fs_lms_link_message' ) );
	}

	public function test_application_expiry_is_checked_before_cron_and_decryption(): void {
		self::assertFalse( $this->callbacks( $this->application( ApplicationStatus::PendingParent, expiry: 'expired' ) )->prepareJoinPage() );
		self::assertStringContainsString( 'истёк', get_query_var( 'fs_lms_link_message' ) );
	}

	public function test_expired_application_after_cron_keeps_expiry_message(): void {
		self::assertFalse( $this->callbacks( $this->application( ApplicationStatus::Expired ) )->prepareJoinPage() );
		self::assertStringContainsString( 'истёк', get_query_var( 'fs_lms_link_message' ) );
	}

	public function test_submitted_form_explains_that_resubmission_is_unnecessary(): void {
		self::assertFalse( $this->callbacks( $this->application( ApplicationStatus::ReadyForReview, 'expired' ) )->prepareJoinPage() );
		self::assertStringContainsString( 'Анкета по этой ссылке уже отправлена', get_query_var( 'fs_lms_link_message' ) );
	}

	public function test_unknown_link_gives_help_without_application_details(): void {
		self::assertFalse( $this->callbacks( null )->prepareJoinPage() );
		self::assertStringContainsString( 'недействительна', get_query_var( 'fs_lms_link_message' ) );
	}
}
