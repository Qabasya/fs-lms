<?php

declare( strict_types=1 );

namespace Unit\Modules\Masterclass;

use Inc\Contracts\ClockInterface;
use Inc\Modules\Masterclass\Config\MasterclassConfig;
use Inc\Modules\Masterclass\DTO\MasterclassAccountDTO;
use Inc\Modules\Masterclass\DTO\MasterclassDTO;
use Inc\Modules\Masterclass\Enums\LoginPlace;
use Inc\Modules\Masterclass\Enums\MasterclassLogAction;
use Inc\Modules\Masterclass\Repositories\MasterclassAccountRepository;
use Inc\Modules\Masterclass\Repositories\MasterclassRepository;
use Inc\Modules\Masterclass\Services\MasterclassLoginTracker;
use Inc\Modules\Masterclass\Services\MasterclassLogWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Вход под временным аккаунтом: где вошёл ребёнок (до начала / на месте / дома), одноразовые отметки, обход капчи (Ш6).
 */
class MasterclassLoginTrackerTest extends TestCase {

	private string $now = '2026-11-10 08:00:00';

	/** @var MasterclassAccountRepository&MockObject */
	private $accounts;

	/** @var MasterclassRepository&MockObject */
	private $masterclasses;

	/** @var MasterclassLogWriter&MockObject */
	private $log;

	protected function setUp(): void {
		$this->accounts      = $this->createMock( MasterclassAccountRepository::class );
		$this->masterclasses = $this->createMock( MasterclassRepository::class );
		$this->log           = $this->createMock( MasterclassLogWriter::class );
	}

	private function tracker(): MasterclassLoginTracker {
		$config = $this->createStub( MasterclassConfig::class );
		$config->method( 'homeTimeoutMinutes' )->willReturn( 60 );

		$clock = $this->createStub( ClockInterface::class );
		$clock->method( 'now' )->willReturnCallback( fn (): string => $this->now );

		return new MasterclassLoginTracker( $this->accounts, $this->masterclasses, $config, $this->log, $clock );
	}

	private function masterclass( array $over = array() ): MasterclassDTO {
		return MasterclassDTO::fromArray( array_merge(
			array(
				'id'        => 7,
				'starts_at' => '2026-11-10 06:00:00',
				'ends_at'   => '2026-11-10 11:00:00',
				'freeze_at' => '2026-11-24 11:00:00',
			),
			$over
		) );
	}

	private function account( array $over = array() ): MasterclassAccountDTO {
		return MasterclassAccountDTO::fromArray( array_merge(
			array( 'id' => 5, 'masterclass_id' => 7, 'user_id' => 105, 'state' => 'active' ),
			$over
		) );
	}

	private function user( int $id = 105 ): \WP_User {
		$user     = new \WP_User();
		$user->ID = $id;

		return $user;
	}

	// ── classify ────────────────────────────────────────────────────────

	#[DataProvider( 'classifyProvider' )]
	public function test_classify( string $now, ?string $firstLoginAt, LoginPlace $expected ): void {
		$account = $this->account( array( 'first_login_at' => $firstLoginAt ) );

		self::assertSame( $expected, $this->tracker()->classify( $this->masterclass(), $account, $now ) );
	}

	public static function classifyProvider(): array {
		return array(
			'до начала'                         => array( '2026-11-10 05:59:59', null, LoginPlace::BeforeStart ),
			'ровно в начало окна'               => array( '2026-11-10 06:00:00', null, LoginPlace::OnSite ),
			'первый вход в окне'                => array( '2026-11-10 08:00:00', null, LoginPlace::OnSite ),
			'первый вход ровно в конец окна'    => array( '2026-11-10 11:00:00', null, LoginPlace::OnSite ),
			'первый вход после окна'            => array( '2026-11-10 11:00:01', null, LoginPlace::AtHome ),
			'повтор через 59 минут'             => array( '2026-11-10 08:59:00', '2026-11-10 08:00:00', LoginPlace::OnSite ),
			'повтор через 60 минут'             => array( '2026-11-10 09:00:00', '2026-11-10 08:00:00', LoginPlace::AtHome ),
			'повтор после окна раньше часа'     => array( '2026-11-10 11:10:00', '2026-11-10 11:00:00', LoginPlace::AtHome ),
			'до начала при повторном входе'     => array( '2026-11-10 05:00:00', '2026-11-10 04:00:00', LoginPlace::BeforeStart ),
		);
	}

	// ── onLogin ─────────────────────────────────────────────────────────

	public function test_login_without_a_live_account_is_ignored(): void {
		$this->accounts->method( 'findByUserId' )->willReturn( null );
		$this->accounts->expects( self::never() )->method( 'markFirstLogin' );
		$this->log->expects( self::never() )->method( 'write' );

		self::assertNull( $this->tracker()->onLogin( $this->user(), '10.0.0.1' ) );
	}

	#[DataProvider( 'notLiveProvider' )]
	public function test_deleted_and_enrolled_accounts_are_ignored( array $over ): void {
		$this->accounts->method( 'findByUserId' )->willReturn( $this->account( $over ) );
		$this->accounts->expects( self::never() )->method( 'markFirstLogin' );

		self::assertNull( $this->tracker()->onLogin( $this->user(), '10.0.0.1' ) );
	}

	public static function notLiveProvider(): array {
		return array(
			'удалён'   => array( array( 'deleted_at' => '2026-11-09 00:00:00' ) ),
			'зачислен' => array( array( 'state' => 'enrolled' ) ),
		);
	}

	public function test_login_before_start_only_writes_the_journal(): void {
		$this->now = '2026-11-10 05:00:00';
		$this->accounts->method( 'findByUserId' )->willReturn( $this->account() );
		$this->masterclasses->method( 'find' )->willReturn( $this->masterclass() );
		$this->accounts->expects( self::never() )->method( 'markFirstLogin' );
		$this->accounts->expects( self::never() )->method( 'markHome' );
		$this->log->expects( self::once() )->method( 'write' )->with(
			MasterclassLogAction::LoginBeforeStart,
			self::callback( static fn ( array $d ): bool => 5 === $d['account_id'] && 7 === $d['masterclass_id'] && '10.0.0.1' === $d['context']['ip'] )
		);

		self::assertSame( LoginPlace::BeforeStart, $this->tracker()->onLogin( $this->user(), '10.0.0.1' ) );
	}

	public function test_first_login_on_site_is_marked_and_logged_once(): void {
		$this->accounts->method( 'findByUserId' )->willReturn( $this->account() );
		$this->masterclasses->method( 'find' )->willReturn( $this->masterclass() );
		$this->accounts->expects( self::once() )->method( 'markFirstLogin' )->with( 5, $this->now )->willReturn( true );
		$this->accounts->expects( self::never() )->method( 'markHome' );
		$this->log->expects( self::once() )->method( 'write' )->with( MasterclassLogAction::LoginOnSite, self::anything() );

		self::assertSame( LoginPlace::OnSite, $this->tracker()->onLogin( $this->user(), '10.0.0.1' ) );
	}

	public function test_repeat_login_on_site_is_not_logged_again(): void {
		$this->accounts->method( 'findByUserId' )->willReturn( $this->account( array( 'first_login_at' => '2026-11-10 07:30:00' ) ) );
		$this->masterclasses->method( 'find' )->willReturn( $this->masterclass() );
		$this->accounts->method( 'markFirstLogin' )->willReturn( false );
		$this->log->expects( self::never() )->method( 'write' );

		self::assertSame( LoginPlace::OnSite, $this->tracker()->onLogin( $this->user(), '10.0.0.1' ) );
	}

	public function test_login_at_home_marks_home_and_logs_with_ip(): void {
		$this->now = '2026-11-10 15:00:00';
		$this->accounts->method( 'findByUserId' )->willReturn( $this->account( array( 'first_login_at' => '2026-11-10 08:00:00' ) ) );
		$this->masterclasses->method( 'find' )->willReturn( $this->masterclass() );
		$this->accounts->method( 'markFirstLogin' )->willReturn( false );
		$this->accounts->expects( self::once() )->method( 'markHome' )->with( 5, $this->now )->willReturn( true );
		$this->log->expects( self::once() )->method( 'write' )->with(
			MasterclassLogAction::LoginAtHome,
			self::callback( static fn ( array $d ): bool => '10.0.0.9' === $d['context']['ip'] )
		);

		self::assertSame( LoginPlace::AtHome, $this->tracker()->onLogin( $this->user(), '10.0.0.9' ) );
	}

	public function test_repeat_home_login_is_not_logged_again(): void {
		$this->now = '2026-11-10 15:00:00';
		$this->accounts->method( 'findByUserId' )->willReturn( $this->account( array( 'first_login_at' => '2026-11-10 08:00:00', 'home_at' => '2026-11-10 12:00:00' ) ) );
		$this->masterclasses->method( 'find' )->willReturn( $this->masterclass() );
		$this->accounts->method( 'markFirstLogin' )->willReturn( false );
		$this->accounts->method( 'markHome' )->willReturn( false );
		$this->log->expects( self::never() )->method( 'write' );

		self::assertSame( LoginPlace::AtHome, $this->tracker()->onLogin( $this->user(), '10.0.0.9' ) );
	}

	// ── onPlayerOpen ────────────────────────────────────────────────────

	public function test_player_open_after_the_window_marks_home_even_with_a_live_session(): void {
		$this->now = '2026-11-10 12:00:00';
		$this->accounts->method( 'findByUserId' )->willReturn( $this->account( array( 'first_login_at' => '2026-11-10 08:00:00' ) ) );
		$this->masterclasses->method( 'find' )->willReturn( $this->masterclass() );
		$this->accounts->expects( self::once() )->method( 'markHome' )->with( 5, $this->now )->willReturn( true );
		$this->log->expects( self::once() )->method( 'write' )->with( MasterclassLogAction::LoginAtHome, self::anything() );

		$this->tracker()->onPlayerOpen( 105 );
	}

	public function test_player_open_when_home_is_already_marked_is_silent(): void {
		$this->now = '2026-11-10 12:00:00';
		$this->accounts->method( 'findByUserId' )->willReturn( $this->account() );
		$this->masterclasses->method( 'find' )->willReturn( $this->masterclass() );
		$this->accounts->method( 'markHome' )->willReturn( false );
		$this->log->expects( self::never() )->method( 'write' );

		$this->tracker()->onPlayerOpen( 105 );
	}

	public function test_player_open_inside_the_window_marks_nothing(): void {
		$this->accounts->method( 'findByUserId' )->willReturn( $this->account() );
		$this->masterclasses->method( 'find' )->willReturn( $this->masterclass() );
		$this->accounts->expects( self::never() )->method( 'markHome' );

		$this->tracker()->onPlayerOpen( 105 );
	}

	public function test_player_open_without_a_live_account_marks_nothing(): void {
		$this->accounts->method( 'findByUserId' )->willReturn( null );
		$this->accounts->expects( self::never() )->method( 'markHome' );

		$this->tracker()->onPlayerOpen( 105 );
	}

	// ── allowsCaptchaBypass ─────────────────────────────────────────────

	public function test_bypass_for_a_student_login_while_the_window_runs(): void {
		$this->accounts->method( 'find' )->with( 5 )->willReturn( $this->account() );
		$this->masterclasses->expects( self::once() )->method( 'runningFor' )->with( $this->now, 30 )->willReturn( array( $this->masterclass() ) );

		self::assertTrue( $this->tracker()->allowsCaptchaBypass( 'student-5' ) );
	}

	#[DataProvider( 'foreignLoginProvider' )]
	public function test_bypass_is_refused_for_any_other_login( string $login ): void {
		$this->accounts->expects( self::never() )->method( 'find' );

		self::assertFalse( $this->tracker()->allowsCaptchaBypass( $login ) );
	}

	public static function foreignLoginProvider(): array {
		return array(
			'обычный логин'    => array( 'ivanov' ),
			'пустой'           => array( '' ),
			'с хвостом'        => array( 'student-5x' ),
			'с префиксом'      => array( 'xstudent-5' ),
			'без номера'       => array( 'student-' ),
			'нулевой номер'    => array( 'student-0' ),
			'заглавные'        => array( 'STUDENT-5' ),
		);
	}

	public function test_bypass_is_refused_outside_the_window(): void {
		$this->accounts->method( 'find' )->willReturn( $this->account() );
		$this->masterclasses->method( 'runningFor' )->willReturn( array() );

		self::assertFalse( $this->tracker()->allowsCaptchaBypass( 'student-5' ) );
	}

	public function test_bypass_is_refused_when_another_masterclass_is_running(): void {
		$this->accounts->method( 'find' )->willReturn( $this->account() );
		$this->masterclasses->method( 'runningFor' )->willReturn( array( $this->masterclass( array( 'id' => 8 ) ) ) );

		self::assertFalse( $this->tracker()->allowsCaptchaBypass( 'student-5' ) );
	}

	public function test_bypass_is_refused_for_a_deleted_or_missing_account(): void {
		$this->accounts->method( 'find' )->willReturnOnConsecutiveCalls(
			null,
			$this->account( array( 'deleted_at' => '2026-11-09 00:00:00' ) ),
			$this->account( array( 'state' => 'enrolled' ) )
		);
		$this->masterclasses->method( 'runningFor' )->willReturn( array( $this->masterclass() ) );
		$tracker = $this->tracker();

		self::assertFalse( $tracker->allowsCaptchaBypass( 'student-5' ) );
		self::assertFalse( $tracker->allowsCaptchaBypass( 'student-5' ) );
		self::assertFalse( $tracker->allowsCaptchaBypass( 'student-5' ) );
	}

	public function test_a_storage_failure_never_grants_the_bypass(): void {
		$this->accounts->method( 'find' )->willThrowException( new \RuntimeException( 'no table' ) );

		self::assertFalse( $this->tracker()->allowsCaptchaBypass( 'student-5' ) );
	}
}
