<?php

declare( strict_types=1 );

namespace Unit\Modules\Masterclass;

use Inc\Modules\Masterclass\Enums\AccountDeleteReason;
use Inc\Modules\Masterclass\Enums\LoginPlace;
use Inc\Modules\Masterclass\Enums\MasterclassAccountState;
use Inc\Modules\Masterclass\Enums\MasterclassContactKind;
use Inc\Modules\Masterclass\Enums\MasterclassLogAction;
use PHPUnit\Framework\TestCase;

/**
 * Энумы модуля: значения хранятся в БД и приходят из запроса, поэтому зафиксированы тестом, а подписи
 * есть у каждого кейса.
 */
class MasterclassEnumsTest extends TestCase {

	/**
	 * @param class-string<\BackedEnum> $enum
	 * @param array<string, string>     $expected Имя кейса → значение.
	 */
	private function assertValues( string $enum, array $expected ): void {
		$actual = array();
		foreach ( $enum::cases() as $case ) {
			$actual[ $case->name ] = $case->value;
		}

		self::assertSame( $expected, $actual, "значения $enum хранятся в БД и менять их нельзя без data-миграции" );
	}

	/**
	 * @param array<\BackedEnum> $cases
	 */
	private function assertLabelsFilledAndDistinct( array $cases ): void {
		$labels = array_map( static fn ( $case ): string => $case->label(), $cases );

		foreach ( $labels as $i => $label ) {
			self::assertNotSame( '', trim( $label ), 'подпись ' . $cases[ $i ]->name . ' не пуста' );
		}
		self::assertSame( count( $labels ), count( array_unique( $labels ) ), 'подписи не повторяются' );
	}

	public function test_account_state_values_and_labels(): void {
		$this->assertValues(
			MasterclassAccountState::class,
			array( 'Active' => 'active', 'Applied' => 'applied', 'Enrolled' => 'enrolled' )
		);
		$this->assertLabelsFilledAndDistinct( MasterclassAccountState::cases() );
	}

	public function test_login_place_values_and_labels(): void {
		$this->assertValues(
			LoginPlace::class,
			array( 'BeforeStart' => 'before_start', 'OnSite' => 'on_site', 'AtHome' => 'at_home' )
		);
		$this->assertLabelsFilledAndDistinct( LoginPlace::cases() );
	}

	public function test_contact_kind_values_and_labels(): void {
		$this->assertValues(
			MasterclassContactKind::class,
			array( 'Open' => 'open', 'Phone' => 'phone', 'Messenger' => 'messenger', 'Apply' => 'apply' )
		);
		$this->assertLabelsFilledAndDistinct( MasterclassContactKind::cases() );
	}

	public function test_contact_kind_from_request_knows_only_its_own_values(): void {
		self::assertSame( MasterclassContactKind::Phone, MasterclassContactKind::fromRequest( 'phone' ) );
		self::assertSame( MasterclassContactKind::Apply, MasterclassContactKind::fromRequest( 'apply' ) );

		foreach ( array( '', 'PHONE', 'call', 'phone ', 'Open' ) as $unknown ) {
			self::assertNull( MasterclassContactKind::fromRequest( $unknown ), "«{$unknown}» — неизвестный вид" );
		}
	}

	public function test_delete_reason_values_and_labels(): void {
		$this->assertValues(
			AccountDeleteReason::class,
			array(
				'Unused'       => 'unused',
				'Expired'      => 'expired',
				'Requested'    => 'requested',
				'UserDeleted'  => 'user_deleted',
				'CreateFailed' => 'create_failed',
				'GroupDeleted' => 'group_deleted',
			)
		);
		$this->assertLabelsFilledAndDistinct( AccountDeleteReason::cases() );

		foreach ( AccountDeleteReason::cases() as $reason ) {
			self::assertLessThanOrEqual( 20, strlen( $reason->value ), 'значение влезает в `delete_reason varchar(20)`' );
		}
	}

	public function test_log_action_values_and_labels(): void {
		$this->assertValues(
			MasterclassLogAction::class,
			array(
				'MasterclassCreated'    => 'masterclass_created',
				'MasterclassUpdated'    => 'masterclass_updated',
				'MasterclassExtended'   => 'masterclass_extended',
				'DeleteRequested'       => 'delete_requested',
				'MasterclassPurged'     => 'masterclass_purged',
				'AccountsCreated'       => 'accounts_created',
				'AccountDeleted'        => 'account_deleted',
				'AccountDetached'       => 'account_detached',
				'FlyersPrinted'         => 'flyers_printed',
				'LoginBeforeStart'      => 'login_before_start',
				'LoginOnSite'           => 'login_on_site',
				'LoginAtHome'           => 'login_at_home',
				'Frozen'                => 'frozen',
				'ContactOpened'         => 'contact_opened',
				'ContactPhone'          => 'contact_phone',
				'ContactMessenger'      => 'contact_messenger',
				'ApplyClicked'          => 'apply_clicked',
				'ApplicationBound'      => 'application_bound',
				'ApplicationBindFailed' => 'application_bind_failed',
				'Enrolled'              => 'enrolled',
				'LoginsUnlocked'        => 'logins_unlocked',
			)
		);
		$this->assertLabelsFilledAndDistinct( MasterclassLogAction::cases() );

		foreach ( MasterclassLogAction::cases() as $action ) {
			self::assertLessThanOrEqual( 40, strlen( $action->value ), 'значение влезает в `action varchar(40)`' );
		}
	}

	public function test_log_action_for_every_contact_kind(): void {
		self::assertSame( MasterclassLogAction::ContactOpened, MasterclassLogAction::fromContact( MasterclassContactKind::Open ) );
		self::assertSame( MasterclassLogAction::ContactPhone, MasterclassLogAction::fromContact( MasterclassContactKind::Phone ) );
		self::assertSame( MasterclassLogAction::ContactMessenger, MasterclassLogAction::fromContact( MasterclassContactKind::Messenger ) );
		self::assertSame( MasterclassLogAction::ApplyClicked, MasterclassLogAction::fromContact( MasterclassContactKind::Apply ) );
	}

	public function test_state_value_fits_the_column(): void {
		foreach ( MasterclassAccountState::cases() as $state ) {
			self::assertLessThanOrEqual( 10, strlen( $state->value ), 'значение влезает в `state varchar(10)`' );
		}
	}
}
