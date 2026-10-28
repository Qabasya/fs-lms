<?php

declare( strict_types=1 );

namespace Unit\Modules\Masterclass;

use Inc\Contracts\ClockInterface;
use Inc\Modules\Masterclass\DTO\MasterclassLogInputDTO;
use Inc\Modules\Masterclass\Enums\MasterclassLogAction;
use Inc\Modules\Masterclass\Repositories\MasterclassLogRepository;
use Inc\Modules\Masterclass\Services\MasterclassLogWriter;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * Писатель журнала: подпись «Ученик#N», актор, IP, защита от паролей и гашение сбоев.
 */
class MasterclassLogWriterTest extends TestCase {

	private MasterclassLogRepository&Stub $repository;
	private MasterclassLogWriter $writer;

	/** @var list<MasterclassLogInputDTO> */
	private array $written = array();

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_ajax();
		unset( $GLOBALS['_fs_test_user_id'], $GLOBALS['_fs_test_userdata'] );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';

		$this->written    = array();
		$this->repository = $this->createStub( MasterclassLogRepository::class );
		$this->repository->method( 'create' )->willReturnCallback(
			function ( MasterclassLogInputDTO $input ): int {
				$this->written[] = $input;
				return count( $this->written );
			}
		);

		$clock = $this->createStub( ClockInterface::class );
		$clock->method( 'now' )->willReturn( '2026-11-10 08:00:00' );

		$this->writer = new MasterclassLogWriter( $this->repository, $clock );
	}

	protected function tearDown(): void {
		unset( $_SERVER['REMOTE_ADDR'], $GLOBALS['_fs_test_userdata'] );
		parent::tearDown();
	}

	public function test_account_action_gets_the_numbered_label_and_ids(): void {
		$this->writer->write( MasterclassLogAction::LoginAtHome, array( 'masterclass_id' => 7, 'account_id' => 15, 'actor_type' => 'user', 'actor' => 501 ) );

		$input = $this->written[0];
		self::assertSame( MasterclassLogAction::LoginAtHome, $input->action );
		self::assertSame( 7, $input->masterclassId );
		self::assertSame( 15, $input->accountId );
		self::assertSame( 'Ученик#15', $input->subjectLabel );
		self::assertSame( 'user', $input->actorType );
		self::assertSame( 501, $input->actorUserId );
		self::assertSame( '203.0.113.9', $input->actorIp );
		self::assertSame( '2026-11-10 08:00:00', $input->createdAt, 'время — из часов, UTC' );
	}

	public function test_masterclass_action_has_no_subject(): void {
		$this->writer->write( MasterclassLogAction::MasterclassCreated, array( 'masterclass_id' => 7 ) );

		self::assertNull( $this->written[0]->subjectLabel );
		self::assertNull( $this->written[0]->accountId );
	}

	public function test_non_positive_ids_are_dropped(): void {
		$this->writer->write( MasterclassLogAction::Frozen, array( 'masterclass_id' => 0, 'account_id' => -3 ) );

		self::assertNull( $this->written[0]->masterclassId );
		self::assertNull( $this->written[0]->accountId );
		self::assertNull( $this->written[0]->subjectLabel );
	}

	public function test_actor_defaults_to_the_current_user(): void {
		$GLOBALS['_fs_test_user_id'] = 1;

		$this->writer->write( MasterclassLogAction::MasterclassCreated, array( 'masterclass_id' => 7 ) );

		self::assertSame( 1, $this->written[0]->actorUserId );
		self::assertSame( 'user', $this->written[0]->actorType );
	}

	public function test_without_actor_the_action_is_a_system_one(): void {
		$this->writer->write( MasterclassLogAction::Frozen, array( 'masterclass_id' => 7 ) );

		self::assertNull( $this->written[0]->actorUserId );
		self::assertSame( 'system', $this->written[0]->actorType );
		self::assertNull( $this->written[0]->actorLabel );
	}

	public function test_explicit_system_type_ignores_the_current_user(): void {
		$GLOBALS['_fs_test_user_id'] = 1;

		$this->writer->write( MasterclassLogAction::Frozen, array( 'masterclass_id' => 7, 'actor_type' => 'system' ) );

		self::assertNull( $this->written[0]->actorUserId, 'тик не приписывается администратору, который случайно в сессии' );
		self::assertSame( 'system', $this->written[0]->actorType );
	}

	public function test_actor_label_comes_from_the_profile_unless_given(): void {
		$user               = new \WP_User();
		$user->ID           = 5;
		$user->display_name = 'Иванова А.';
		$GLOBALS['_fs_test_userdata'][5] = $user;

		$this->writer->write( MasterclassLogAction::MasterclassUpdated, array( 'actor' => 5 ) );
		$this->writer->write( MasterclassLogAction::MasterclassUpdated, array( 'actor' => 5, 'actor_label' => 'Из CLI' ) );
		$this->writer->write( MasterclassLogAction::MasterclassUpdated, array( 'actor' => 99 ) );

		self::assertSame( 'Иванова А.', $this->written[0]->actorLabel );
		self::assertSame( 'Из CLI', $this->written[1]->actorLabel );
		self::assertSame( '#99', $this->written[2]->actorLabel, 'пользователя нет в профиле — остаётся номер' );
	}

	public function test_failure_result_is_kept_and_unknown_values_become_success(): void {
		$this->writer->write( MasterclassLogAction::ApplicationBindFailed, array( 'result' => 'failure' ) );
		$this->writer->write( MasterclassLogAction::ApplicationBound, array( 'result' => 'banana' ) );

		self::assertSame( 'failure', $this->written[0]->result );
		self::assertSame( 'success', $this->written[1]->result );
	}

	public function test_context_is_passed_without_secrets(): void {
		$this->writer->write(
			MasterclassLogAction::AccountsCreated,
			array(
				'masterclass_id' => 7,
				'context'        => array(
					'created'  => 25,
					'password' => 'Zx9kQ2mP',
					'nested'   => array( 'user_pass' => 'Zx9kQ2mP', 'keep' => 1 ),
					'token'    => 'abc',
					'Secret'   => 'abc',
					'nonce'    => 'abc',
					'pwd'      => 'abc',
				),
			)
		);

		self::assertSame( array( 'created' => 25, 'nested' => array( 'keep' => 1 ) ), $this->written[0]->context );
		self::assertStringNotContainsString( 'Zx9kQ2mP', (string) wp_json_encode( $this->written[0]->context ) );
	}

	public function test_non_array_context_is_ignored(): void {
		$this->writer->write( MasterclassLogAction::Frozen, array( 'context' => 'строка' ) );

		self::assertSame( array(), $this->written[0]->context );
	}

	public function test_repository_failure_does_not_break_the_action(): void {
		$repository = $this->createMock( MasterclassLogRepository::class );
		$repository->expects( $this->once() )->method( 'create' )->willThrowException( new \RuntimeException( 'База недоступна' ) );
		$clock = $this->createStub( ClockInterface::class );
		$clock->method( 'now' )->willReturn( '2026-11-10 08:00:00' );

		( new MasterclassLogWriter( $repository, $clock ) )->write( MasterclassLogAction::Frozen, array( 'masterclass_id' => 7 ) );

		$this->addToAssertionCount( 1 );
	}
}
