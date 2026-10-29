<?php

declare( strict_types=1 );

namespace Unit\Modules\Masterclass;

use Inc\Modules\Masterclass\DTO\MasterclassAccountDTO;
use Inc\Modules\Masterclass\DTO\MasterclassDTO;
use Inc\Modules\Masterclass\DTO\MasterclassFunnelDTO;
use Inc\Modules\Masterclass\DTO\MasterclassLogDTO;
use Inc\Modules\Masterclass\DTO\MasterclassLogInputDTO;
use Inc\Modules\Masterclass\DTO\MasterclassViewDTO;
use Inc\Modules\Masterclass\Enums\AccountDeleteReason;
use Inc\Modules\Masterclass\Enums\MasterclassAccountState;
use Inc\Modules\Masterclass\Enums\MasterclassLogAction;
use Inc\Modules\Masterclass\Enums\MasterclassStatus;
use PHPUnit\Framework\TestCase;

/**
 * DTO модуля: разбор строк БД с NULL, границы заморозки и окна, сериализация.
 */
class MasterclassDtoTest extends TestCase {

	/**
	 * Строка `fs_lms_masterclasses` как её отдаёт wpdb (всё строками).
	 *
	 * @param array<string, mixed> $over
	 *
	 * @return array<string, mixed>
	 */
	private function masterclassRow( array $over = array() ): array {
		return array_merge(
			array(
				'id'                  => '7',
				'school_name'         => 'Школа №5',
				'subject_key'         => 'inf',
				'lesson_id'           => '321',
				'group_id'            => '41',
				'group_lesson_id'     => '900',
				'teacher_user_id'     => '12',
				'starts_at'           => '2026-11-10 06:00:00',
				'ends_at'             => '2026-11-10 11:00:00',
				'freeze_at'           => '2026-11-24 11:00:00',
				'unused_purge_at'     => '2026-11-17 11:00:00',
				'purge_at'            => '2026-12-24 11:00:00',
				'frozen_at'           => null,
				'unused_purged_at'    => null,
				'delete_requested_at' => null,
				'purged_at'           => null,
				'created_by_user_id'  => '1',
				'created_at'          => '2026-11-01 10:00:00',
				'updated_at'          => '2026-11-01 10:00:00',
			),
			$over
		);
	}

	public function test_masterclass_from_array_casts_types(): void {
		$dto = MasterclassDTO::fromArray( $this->masterclassRow() );

		self::assertSame( 7, $dto->id );
		self::assertSame( 'Школа №5', $dto->schoolName );
		self::assertSame( 321, $dto->lessonId );
		self::assertSame( 41, $dto->groupId );
		self::assertSame( 900, $dto->groupLessonId );
		self::assertSame( 12, $dto->teacherUserId );
		self::assertSame( '2026-11-24 11:00:00', $dto->freezeAt );
		self::assertSame( 1, $dto->createdByUserId );
	}

	public function test_masterclass_from_array_reads_nulls_after_purge(): void {
		$dto = MasterclassDTO::fromArray(
			$this->masterclassRow(
				array(
					'group_id'           => null,
					'group_lesson_id'    => null,
					'purged_at'          => '2026-12-24 11:05:00',
					'created_by_user_id' => null,
				)
			)
		);

		self::assertNull( $dto->groupId );
		self::assertNull( $dto->groupLessonId );
		self::assertNull( $dto->createdByUserId );
		self::assertNull( $dto->frozenAt );
		self::assertTrue( $dto->isPurged() );
	}

	public function test_empty_strings_are_read_as_null(): void {
		$dto = MasterclassDTO::fromArray( $this->masterclassRow( array( 'group_id' => '', 'frozen_at' => '' ) ) );

		self::assertNull( $dto->groupId );
		self::assertNull( $dto->frozenAt );
	}

	public function test_is_frozen_at_the_boundaries(): void {
		$dto = MasterclassDTO::fromArray( $this->masterclassRow() );

		self::assertFalse( $dto->isFrozen( '2026-11-24 10:59:59' ), 'за секунду до freeze_at — открыт' );
		self::assertTrue( $dto->isFrozen( '2026-11-24 11:00:00' ), 'в момент freeze_at — уже закрыт' );
		self::assertTrue( $dto->isFrozen( '2026-11-24 11:00:01' ) );
		self::assertFalse( $dto->isFrozen( '2026-11-10 08:00:00' ), 'в окне мастер-класса доступ открыт' );
	}

	public function test_freeze_is_derived_from_time_not_from_the_tick_mark(): void {
		$beforeTick = MasterclassDTO::fromArray( $this->masterclassRow() );
		$afterTick  = MasterclassDTO::fromArray( $this->masterclassRow( array( 'frozen_at' => '2026-11-24 11:15:00' ) ) );
		$extended   = MasterclassDTO::fromArray( $this->masterclassRow( array( 'frozen_at' => null, 'freeze_at' => '2026-12-08 11:00:00' ) ) );

		self::assertTrue( $beforeTick->isFrozen( '2026-11-24 11:01:00' ), 'тик ещё не отметил, а доступ уже закрыт' );
		self::assertTrue( $afterTick->isFrozen( '2026-11-24 11:16:00' ) );
		self::assertFalse( $extended->isFrozen( '2026-11-24 11:16:00' ), 'продление сдвинуло freeze_at — доступ вернулся' );
	}

	public function test_is_running_includes_both_ends_of_the_window(): void {
		$dto = MasterclassDTO::fromArray( $this->masterclassRow() );

		self::assertFalse( $dto->isRunning( '2026-11-10 05:59:59' ) );
		self::assertTrue( $dto->isRunning( '2026-11-10 06:00:00' ), 'now == starts_at — идёт' );
		self::assertTrue( $dto->isRunning( '2026-11-10 08:30:00' ) );
		self::assertTrue( $dto->isRunning( '2026-11-10 11:00:00' ), 'now == ends_at — ещё идёт' );
		self::assertFalse( $dto->isRunning( '2026-11-10 11:00:01' ) );
	}

	public function test_delete_requested_flag(): void {
		self::assertFalse( MasterclassDTO::fromArray( $this->masterclassRow() )->isDeleteRequested() );
		self::assertTrue( MasterclassDTO::fromArray( $this->masterclassRow( array( 'delete_requested_at' => '2026-11-11 09:00:00' ) ) )->isDeleteRequested() );
	}

	public function test_status_follows_the_clock_through_the_whole_lifecycle(): void {
		$dto = MasterclassDTO::fromArray( $this->masterclassRow() );

		self::assertSame( MasterclassStatus::Waiting, $dto->status( '2026-11-10 05:59:59' ) );
		self::assertSame( MasterclassStatus::Running, $dto->status( '2026-11-10 06:00:00' ), 'начало окна — уже идёт' );
		self::assertSame( MasterclassStatus::Running, $dto->status( '2026-11-10 11:00:00' ), 'конец окна — ещё идёт' );
		self::assertSame( MasterclassStatus::OpenAtHome, $dto->status( '2026-11-10 11:00:01' ) );
		self::assertSame( MasterclassStatus::OpenAtHome, $dto->status( '2026-11-24 10:59:59' ) );
		self::assertSame( MasterclassStatus::Frozen, $dto->status( '2026-11-24 11:00:00' ) );
	}

	public function test_status_of_a_deleting_or_purged_masterclass_wins_over_time(): void {
		$deleting = MasterclassDTO::fromArray( $this->masterclassRow( array( 'delete_requested_at' => '2026-11-11 09:00:00' ) ) );
		$purged   = MasterclassDTO::fromArray( $this->masterclassRow( array( 'delete_requested_at' => '2026-11-11 09:00:00', 'purged_at' => '2026-11-12 09:00:00', 'group_id' => null ) ) );

		self::assertSame( MasterclassStatus::Deleting, $deleting->status( '2026-11-10 05:00:00' ) );
		self::assertSame( MasterclassStatus::Deleting, $deleting->status( '2027-01-01 00:00:00' ) );
		self::assertSame( MasterclassStatus::Purged, $purged->status( '2026-11-10 05:00:00' ) );
	}

	public function test_status_labels_are_the_words_of_the_list(): void {
		self::assertSame(
			array( 'ждёт', 'идёт', 'открыт дома', 'заморожен', 'удаляется', 'вычищен' ),
			array_map( static fn ( MasterclassStatus $s ): string => $s->label(), MasterclassStatus::cases() )
		);
	}

	/**
	 * @param array<string, mixed> $over
	 *
	 * @return array<string, mixed>
	 */
	private function accountRow( array $over = array() ): array {
		return array_merge(
			array(
				'id'                => '15',
				'masterclass_id'    => '7',
				'user_id'           => '501',
				'person_id'         => '88',
				'student_record_id' => '300',
				'state'             => 'active',
				'first_login_at'    => null,
				'home_at'           => null,
				'contact_at'        => null,
				'application_id'    => null,
				'deleted_at'        => null,
				'delete_reason'     => null,
				'created_at'        => '2026-11-01 10:00:00',
				'updated_at'        => '2026-11-01 10:00:00',
			),
			$over
		);
	}

	public function test_account_from_array_with_nulls(): void {
		$dto = MasterclassAccountDTO::fromArray( $this->accountRow() );

		self::assertSame( 15, $dto->id );
		self::assertSame( 15, $dto->number(), 'номер аккаунта — его id' );
		self::assertSame( 501, $dto->userId );
		self::assertSame( MasterclassAccountState::Active, $dto->state );
		self::assertNull( $dto->firstLoginAt );
		self::assertNull( $dto->applicationId );
		self::assertNull( $dto->deleteReason );
		self::assertFalse( $dto->isDeleted() );
	}

	public function test_account_without_user_is_a_pending_number(): void {
		$dto = MasterclassAccountDTO::fromArray( $this->accountRow( array( 'user_id' => null, 'person_id' => null, 'student_record_id' => null ) ) );

		self::assertNull( $dto->userId );
		self::assertNull( $dto->personId );
		self::assertNull( $dto->studentRecordId );
	}

	public function test_deleted_account_keeps_user_id_and_reason(): void {
		$dto = MasterclassAccountDTO::fromArray(
			$this->accountRow(
				array(
					'state'         => 'applied',
					'application_id' => '44',
					'deleted_at'    => '2026-11-20 10:00:00',
					'delete_reason' => 'user_deleted',
				)
			)
		);

		self::assertTrue( $dto->isDeleted() );
		self::assertSame( 501, $dto->userId, 'user_id при удалении сохраняется для журнала' );
		self::assertSame( AccountDeleteReason::UserDeleted, $dto->deleteReason );
		self::assertSame( MasterclassAccountState::Applied, $dto->state );
		self::assertSame( 44, $dto->applicationId );
	}

	public function test_account_with_unknown_stored_values_does_not_break_the_list(): void {
		$dto = MasterclassAccountDTO::fromArray( $this->accountRow( array( 'state' => 'weird', 'delete_reason' => 'weird' ) ) );

		self::assertSame( MasterclassAccountState::Active, $dto->state );
		self::assertNull( $dto->deleteReason );
	}

	public function test_funnel_to_array(): void {
		$funnel = new MasterclassFunnelDTO( created: 60, loggedIn: 40, atHome: 12, contacted: 5, applied: 3, enrolled: 1 );

		self::assertSame(
			array( 'created' => 60, 'loggedIn' => 40, 'atHome' => 12, 'contacted' => 5, 'applied' => 3, 'enrolled' => 1 ),
			$funnel->toArray()
		);
	}

	public function test_view_to_array_exposes_the_state_value(): void {
		$view = new MasterclassViewDTO(
			state:        MasterclassAccountState::Applied,
			frozen:       true,
			showBanner:   false,
			bannerText:   'Текст',
			phone:        '+7 900 000-00-00',
			messengerUrl: 'https://t.me/school',
			applyUrl:     'https://example.test/apply/',
			logoutUrl:    'https://example.test/logout/'
		);

		self::assertSame(
			array(
				'state'        => 'applied',
				'frozen'       => true,
				'showBanner'   => false,
				'bannerText'   => 'Текст',
				'phone'        => '+7 900 000-00-00',
				'messengerUrl' => 'https://t.me/school',
				'applyUrl'     => 'https://example.test/apply/',
				'logoutUrl'    => 'https://example.test/logout/',
			),
			$view->toArray()
		);
	}

	public function test_log_input_to_array_encodes_context_and_cuts_labels(): void {
		$input = new MasterclassLogInputDTO(
			action:        MasterclassLogAction::AccountsCreated,
			result:        'success',
			actorType:     'user',
			actorUserId:   1,
			actorLabel:    str_repeat( 'я', 300 ),
			masterclassId: 7,
			accountId:     15,
			subjectLabel:  'Ученик#15',
			actorIp:       '10.0.0.1',
			context:       array( 'created' => 25, 'note' => 'пачка' ),
			createdAt:     '2026-11-01 10:00:00'
		);

		$row = $input->toArray();

		self::assertSame( 'accounts_created', $row['action'] );
		self::assertSame( 255, mb_strlen( $row['actor_label'] ) );
		self::assertSame( 7, $row['masterclass_id'] );
		self::assertSame( 15, $row['account_id'] );
		self::assertSame( '{"created":25,"note":"пачка"}', $row['context'], 'кириллица не экранируется' );
		self::assertSame( '2026-11-01 10:00:00', $row['created_at'] );
	}

	public function test_log_input_with_empty_context_writes_null(): void {
		$row = ( new MasterclassLogInputDTO(
			action:        MasterclassLogAction::Frozen,
			result:        'success',
			actorType:     'system',
			actorUserId:   null,
			actorLabel:    null,
			masterclassId: 7,
			accountId:     null,
			subjectLabel:  null,
			actorIp:       '',
			context:       array(),
			createdAt:     '2026-11-24 11:15:00'
		) )->toArray();

		self::assertNull( $row['context'] );
		self::assertNull( $row['actor_label'] );
		self::assertNull( $row['account_id'] );
	}

	public function test_log_from_array_decodes_context_and_nulls(): void {
		$dto = MasterclassLogDTO::fromArray(
			array(
				'id'             => '9',
				'action'         => 'login_at_home',
				'result'         => 'success',
				'actor_type'     => 'user',
				'actor_user_id'  => '501',
				'actor_label'    => 'Ученик#15',
				'masterclass_id' => '7',
				'account_id'     => '15',
				'subject_label'  => 'Ученик#15',
				'actor_ip'       => '10.0.0.1',
				'context'        => '{"place":"at_home"}',
				'created_at'     => '2026-11-11 15:00:00',
			)
		);

		self::assertSame( 9, $dto->id );
		self::assertSame( 'login_at_home', $dto->action );
		self::assertSame( 501, $dto->actorUserId );
		self::assertSame( array( 'place' => 'at_home' ), $dto->context );

		$empty = MasterclassLogDTO::fromArray(
			array(
				'id'             => '10',
				'action'         => 'frozen',
				'actor_user_id'  => null,
				'actor_label'    => null,
				'masterclass_id' => null,
				'account_id'     => null,
				'subject_label'  => null,
				'context'        => null,
				'created_at'     => '2026-11-24 11:15:00',
			)
		);

		self::assertNull( $empty->actorUserId );
		self::assertNull( $empty->masterclassId );
		self::assertNull( $empty->accountId );
		self::assertSame( array(), $empty->context );
		self::assertSame( 'success', $empty->result, 'результат по умолчанию' );
	}

	public function test_log_from_array_survives_broken_json(): void {
		$dto = MasterclassLogDTO::fromArray( array( 'id' => '1', 'action' => 'frozen', 'context' => '{oops', 'created_at' => '2026-11-24 11:15:00' ) );

		self::assertSame( array(), $dto->context );
	}
}
