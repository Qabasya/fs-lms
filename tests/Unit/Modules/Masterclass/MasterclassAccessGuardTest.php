<?php

declare( strict_types=1 );

namespace Unit\Modules\Masterclass;

use Inc\Contracts\ClockInterface;
use Inc\DTO\Course\GroupLessonDTO;
use Inc\DTO\Enrollment\StudentRecordDTO;
use Inc\Enums\Access\AccessLevel;
use Inc\Modules\Masterclass\Config\MasterclassConfig;
use Inc\Modules\Masterclass\DTO\MasterclassAccountDTO;
use Inc\Modules\Masterclass\DTO\MasterclassDTO;
use Inc\Modules\Masterclass\Enums\MasterclassAccountState;
use Inc\Modules\Masterclass\Repositories\MasterclassAccountRepository;
use Inc\Modules\Masterclass\Repositories\MasterclassRepository;
use Inc\Modules\Masterclass\Services\MasterclassAccessGuard;
use Inc\Services\Shared\PluginConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Ш1: заморозка мастер-класса понижает доступ временного ученика до `None` ровно по `freeze_at`; `viewFor()` — вид плеера.
 */
class MasterclassAccessGuardTest extends TestCase {

	private const FREEZE = '2026-11-24 11:00:00';

	private string $now = '2026-11-10 08:00:00';

	/** @var MasterclassAccountRepository&\PHPUnit\Framework\MockObject\Stub */
	private $accounts;

	/** @var MasterclassRepository&\PHPUnit\Framework\MockObject\Stub */
	private $masterclasses;

	protected function setUp(): void {
		$this->accounts      = $this->createStub( MasterclassAccountRepository::class );
		$this->masterclasses = $this->createStub( MasterclassRepository::class );
	}

	private function guard(): MasterclassAccessGuard {
		$config = $this->createStub( MasterclassConfig::class );
		$config->method( 'bannerText' )->willReturn( 'Запишитесь' );
		$config->method( 'messengerUrl' )->willReturn( 'https://t.me/x' );

		$plugin = $this->createStub( PluginConfig::class );
		$plugin->method( 'centerContactsFallback' )->willReturn( array( 'phone' => '+7 900', 'email' => '', 'hours' => '', 'address' => '' ) );

		$clock = $this->createStub( ClockInterface::class );
		$clock->method( 'now' )->willReturnCallback( fn (): string => $this->now );

		return new MasterclassAccessGuard( $this->accounts, $this->masterclasses, $config, $plugin, $clock );
	}

	private function masterclass( array $over = array() ): MasterclassDTO {
		return MasterclassDTO::fromArray( array_merge(
			array(
				'id'              => 7,
				'group_id'        => 31,
				'group_lesson_id' => 41,
				'starts_at'       => '2026-11-10 06:00:00',
				'ends_at'         => '2026-11-10 11:00:00',
				'freeze_at'       => self::FREEZE,
			),
			$over
		) );
	}

	private function account( array $over = array() ): MasterclassAccountDTO {
		return MasterclassAccountDTO::fromArray( array_merge(
			array( 'id' => 5, 'masterclass_id' => 7, 'user_id' => 105, 'person_id' => 205, 'state' => 'active' ),
			$over
		) );
	}

	private function record( bool $trial = true ): StudentRecordDTO {
		return StudentRecordDTO::fromArray( array( 'id' => 9, 'student_person_id' => 205, 'group_id' => 31, 'is_trial' => $trial ? 1 : 0 ) );
	}

	private function lesson( int $groupId = 31 ): GroupLessonDTO {
		return GroupLessonDTO::fromArray( array( 'id' => 41, 'group_id' => $groupId, 'position' => 1 ) );
	}

	private function givenAccount( ?MasterclassAccountDTO $account, ?MasterclassDTO $masterclass ): void {
		$this->accounts->method( 'findByPersonId' )->willReturn( $account );
		$this->accounts->method( 'findByUserId' )->willReturn( $account );
		$this->masterclasses->method( 'find' )->willReturn( $masterclass );
	}

	public function test_it_freezes_exactly_at_freeze_at(): void {
		$this->givenAccount( $this->account(), $this->masterclass() );

		$this->now = '2026-11-24 10:59:59';
		self::assertSame( AccessLevel::ReadSubmit, $this->guard()->filterLessonAccess( AccessLevel::ReadSubmit, $this->record(), $this->lesson() ) );

		$this->now = self::FREEZE;
		self::assertSame( AccessLevel::None, $this->guard()->filterLessonAccess( AccessLevel::ReadSubmit, $this->record(), $this->lesson() ) );
	}

	public function test_it_never_raises_the_level(): void {
		$this->givenAccount( $this->account(), $this->masterclass() );

		self::assertSame( AccessLevel::None, $this->guard()->filterLessonAccess( AccessLevel::None, $this->record(), $this->lesson() ) );
		self::assertSame( AccessLevel::Read, $this->guard()->filterLessonAccess( AccessLevel::Read, $this->record(), $this->lesson() ) );
	}

	public function test_a_regular_record_is_untouched_and_the_table_is_not_read(): void {
		$this->accounts = $this->createMock( MasterclassAccountRepository::class );
		$this->accounts->expects( self::never() )->method( 'findByPersonId' );
		$this->now = '2030-01-01 00:00:00';

		self::assertSame( AccessLevel::ReadSubmit, $this->guard()->filterLessonAccess( AccessLevel::ReadSubmit, $this->record( false ), $this->lesson() ) );
	}

	public function test_another_group_is_untouched(): void {
		$this->givenAccount( $this->account(), $this->masterclass() );
		$this->now = '2030-01-01 00:00:00';

		self::assertSame( AccessLevel::ReadSubmit, $this->guard()->filterLessonAccess( AccessLevel::ReadSubmit, $this->record(), $this->lesson( 99 ) ) );
	}

	#[DataProvider( 'notLiveProvider' )]
	public function test_enrolled_deleted_or_missing_account_is_untouched( ?array $account ): void {
		$this->givenAccount( null === $account ? null : $this->account( $account ), $this->masterclass() );
		$this->now = '2030-01-01 00:00:00';

		self::assertSame( AccessLevel::ReadSubmit, $this->guard()->filterLessonAccess( AccessLevel::ReadSubmit, $this->record(), $this->lesson() ) );
	}

	public static function notLiveProvider(): array {
		return array(
			'нет аккаунта' => array( null ),
			'зачислен'     => array( array( 'state' => 'enrolled' ) ),
			'удалён'       => array( array( 'deleted_at' => '2026-11-12 00:00:00' ) ),
		);
	}

	public function test_a_storage_failure_does_not_break_core_access(): void {
		$this->accounts->method( 'findByPersonId' )->willThrowException( new \RuntimeException( 'no table' ) );

		self::assertSame( AccessLevel::Read, $this->guard()->filterLessonAccess( AccessLevel::Read, $this->record(), $this->lesson() ) );
	}

	public function test_view_is_null_without_a_live_account(): void {
		$this->givenAccount( null, null );

		self::assertNull( $this->guard()->viewFor( 105 ) );
	}

	public function test_banner_is_hidden_during_the_window_for_a_first_visit(): void {
		$this->givenAccount( $this->account(), $this->masterclass() );
		$this->now = '2026-11-10 08:00:00';

		$view = $this->guard()->viewFor( 105 );

		self::assertFalse( $view->showBanner );
		self::assertFalse( $view->frozen );
		self::assertSame( '+7 900', $view->phone );
		self::assertSame( 'https://t.me/x', $view->messengerUrl );
		self::assertSame( 'Запишитесь', $view->bannerText );
		self::assertSame( MasterclassAccountState::Active, $view->state );
	}

	public function test_banner_shows_after_the_window(): void {
		$this->givenAccount( $this->account(), $this->masterclass() );
		$this->now = '2026-11-10 11:00:01';

		self::assertTrue( $this->guard()->viewFor( 105 )->showBanner );
	}

	public function test_banner_shows_in_the_window_when_home_is_marked(): void {
		$this->givenAccount( $this->account( array( 'home_at' => '2026-11-10 07:00:00' ) ), $this->masterclass() );
		$this->now = '2026-11-10 08:00:00';

		self::assertTrue( $this->guard()->viewFor( 105 )->showBanner );
	}

	public function test_no_banner_when_frozen(): void {
		$this->givenAccount( $this->account(), $this->masterclass() );
		$this->now = self::FREEZE;

		$view = $this->guard()->viewFor( 105 );

		self::assertTrue( $view->frozen );
		self::assertFalse( $view->showBanner );
	}

	public function test_view_uses_account_state(): void {
		$this->givenAccount( $this->account( array( 'state' => 'applied' ) ), $this->masterclass() );

		self::assertSame( MasterclassAccountState::Applied, $this->guard()->viewFor( 105 )->state );
	}
}
