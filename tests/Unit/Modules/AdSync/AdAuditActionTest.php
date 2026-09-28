<?php

declare( strict_types=1 );

namespace Unit\Modules\AdSync;

use Inc\Modules\AdSync\Enums\AdAuditAction;
use PHPUnit\Framework\TestCase;

/**
 * Итог сервера → действие журнала «Зачисления».
 */
class AdAuditActionTest extends TestCase {

	public function test_outcome_maps_to_action(): void {
		self::assertSame( AdAuditAction::AccountCreated, AdAuditAction::fromOutcome( 'created', 'provision' ) );
		self::assertSame( AdAuditAction::AccountReactivated, AdAuditAction::fromOutcome( 'reactivated', 'provision' ) );
		self::assertSame( AdAuditAction::AccountDisabled, AdAuditAction::fromOutcome( 'absent', 'deprovision' ) );
		self::assertSame( AdAuditAction::PasswordChanged, AdAuditAction::fromOutcome( 'password_changed', 'password' ) );
	}

	public function test_server_without_outcome_falls_back_to_event(): void {
		self::assertSame( AdAuditAction::AccountDisabled, AdAuditAction::fromOutcome( '', 'deprovision' ) );
		self::assertSame( AdAuditAction::PasswordChanged, AdAuditAction::fromOutcome( '', 'password' ) );
		self::assertSame( AdAuditAction::AccountUpdated, AdAuditAction::fromOutcome( '', 'provision' ) );
	}
}
