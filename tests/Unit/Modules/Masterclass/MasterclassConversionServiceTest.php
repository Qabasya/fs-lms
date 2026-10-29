<?php

declare( strict_types=1 );

namespace Unit\Modules\Masterclass;

use Inc\Contracts\ClockInterface;
use Inc\Modules\Masterclass\DTO\MasterclassAccountDTO;
use Inc\Modules\Masterclass\Enums\MasterclassContactKind;
use Inc\Modules\Masterclass\Enums\MasterclassLogAction;
use Inc\Modules\Masterclass\Repositories\MasterclassAccountRepository;
use Inc\Modules\Masterclass\Services\MasterclassConversionService;
use Inc\Modules\Masterclass\Services\MasterclassLogWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Клик в окне «Связаться»: отметка «связался» один раз, каждый клик — в журнал; чужие и удалённые аккаунты игнорируются.
 */
class MasterclassConversionServiceTest extends TestCase {

	private const NOW = '2026-11-10 12:00:00';

	private MasterclassAccountRepository&MockObject $accounts;
	private MasterclassLogWriter&MockObject $log;
	private MasterclassConversionService $service;

	protected function setUp(): void {
		$this->accounts = $this->createMock( MasterclassAccountRepository::class );
		$this->log      = $this->createMock( MasterclassLogWriter::class );

		$clock = $this->createStub( ClockInterface::class );
		$clock->method( 'now' )->willReturn( self::NOW );

		$this->service = new MasterclassConversionService( $this->accounts, $this->log, $clock );
	}

	private function account( array $over = array() ): MasterclassAccountDTO {
		return MasterclassAccountDTO::fromArray( array_merge(
			array( 'id' => 5, 'masterclass_id' => 7, 'user_id' => 105, 'state' => 'active' ),
			$over
		) );
	}

	#[DataProvider( 'kindProvider' )]
	public function test_every_click_is_marked_and_logged( MasterclassContactKind $kind, MasterclassLogAction $action ): void {
		$this->accounts->method( 'findByUserId' )->with( 105 )->willReturn( $this->account() );
		$this->accounts->expects( self::once() )->method( 'markContact' )->with( 5, self::NOW )->willReturn( true );
		$this->log->expects( self::once() )->method( 'write' )->with(
			$action,
			self::callback( static fn ( array $d ): bool => 5 === $d['account_id'] && 7 === $d['masterclass_id'] && 105 === $d['actor'] )
		);

		$this->service->logContact( 105, $kind );
	}

	public static function kindProvider(): array {
		return array(
			'окно'        => array( MasterclassContactKind::Open, MasterclassLogAction::ContactOpened ),
			'телефон'     => array( MasterclassContactKind::Phone, MasterclassLogAction::ContactPhone ),
			'мессенджер'  => array( MasterclassContactKind::Messenger, MasterclassLogAction::ContactMessenger ),
			'заявка'      => array( MasterclassContactKind::Apply, MasterclassLogAction::ApplyClicked ),
		);
	}

	public function test_a_repeat_click_is_still_logged_but_marks_nothing_new(): void {
		$this->accounts->method( 'findByUserId' )->willReturn( $this->account( array( 'contact_at' => '2026-11-10 11:00:00' ) ) );
		$this->accounts->method( 'markContact' )->willReturn( false );
		$this->log->expects( self::once() )->method( 'write' );

		$this->service->logContact( 105, MasterclassContactKind::Phone );
	}

	public function test_a_user_without_an_account_is_ignored(): void {
		$this->accounts->method( 'findByUserId' )->willReturn( null );
		$this->accounts->expects( self::never() )->method( 'markContact' );
		$this->log->expects( self::never() )->method( 'write' );

		$this->service->logContact( 7, MasterclassContactKind::Open );
	}

	#[DataProvider( 'notLiveProvider' )]
	public function test_deleted_and_enrolled_accounts_are_ignored( array $over ): void {
		$this->accounts->method( 'findByUserId' )->willReturn( $this->account( $over ) );
		$this->accounts->expects( self::never() )->method( 'markContact' );
		$this->log->expects( self::never() )->method( 'write' );

		$this->service->logContact( 105, MasterclassContactKind::Open );
	}

	public static function notLiveProvider(): array {
		return array(
			'удалён'   => array( array( 'deleted_at' => '2026-11-09 00:00:00' ) ),
			'зачислен' => array( array( 'state' => 'enrolled' ) ),
		);
	}
}
