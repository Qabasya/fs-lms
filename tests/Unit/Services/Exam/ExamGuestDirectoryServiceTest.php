<?php

declare( strict_types=1 );

namespace Tests\Unit\Services\Exam;

use Inc\Repositories\WPDBRepositories\ExamParticipantRepository;
use Inc\Services\Exam\ExamGuestDirectoryService;
use Inc\Services\Exam\ExamTime;
use Inc\Services\Exam\GuestParticipantMaterializer;
use PHPUnit\Framework\TestCase;

class ExamGuestDirectoryServiceTest extends TestCase {

	private function service( array $rows ): ExamGuestDirectoryService {
		$participants = $this->createMock( ExamParticipantRepository::class );
		$participants->method( 'listGuestRows' )->willReturn( $rows );
		$guestData = $this->createMock( GuestParticipantMaterializer::class );
		$guestData->method( 'decryptName' )->willReturn( 'Иванов Пётр' );
		$guestData->method( 'decryptContacts' )->willReturn( array( 'phone' => '+79990001122', 'messenger' => '@ivan' ) );
		$time = $this->createMock( ExamTime::class );
		$time->method( 'toLocal' )->willReturnCallback( static fn ( string $u ): string => $u );

		return new ExamGuestDirectoryService( $participants, $guestData, $time );
	}

	public function test_row_has_form_data_and_session(): void {
		$result = $this->service( array(
			array(
				'id' => 1, 'person_id' => null, 'school_name' => 'Школа 5', 'grade' => 9,
				'created_at' => '2026-03-01 10:00:00', 'updated_at' => '2026-03-01 10:00:00', 'name_enc' => 'x',
				'event_title' => 'Пробный ЕГЭ', 'scheduled_at' => '2026-03-12 07:00:00', 'registration_status' => 'confirmed',
			),
		) )->page( 1, 20 );

		self::assertSame( 1, $result['total'] );
		self::assertSame( 'Иванов Пётр', $result['rows'][0]['name'] );
		self::assertSame( '+79990001122', $result['rows'][0]['phone'] );
		self::assertSame( 'Школа 5', $result['rows'][0]['school'] );
		self::assertSame( '9', $result['rows'][0]['grade'] );
		self::assertSame( '2026-03-12 07:00:00', $result['rows'][0]['session'] );
		self::assertSame( 'Запись подтверждена', $result['rows'][0]['status'] );
	}

	public function test_anonymized_guest_shows_no_personal_data(): void {
		$result = $this->service( array(
			array( 'id' => 2, 'person_id' => null, 'anonymized_at' => '2026-04-01 00:00:00', 'created_at' => '2026-03-01 10:00:00', 'updated_at' => '2026-03-01 10:00:00' ),
		) )->page( 1, 20 );

		self::assertSame( 'Обезличен', $result['rows'][0]['name'] );
		self::assertSame( '', $result['rows'][0]['phone'] );
		self::assertSame( '', $result['rows'][0]['session'] );
	}

	public function test_sorts_by_name_and_session_with_missing_session_last(): void {
		$row  = static fn ( int $id, ?string $at ): array => array( 'id' => $id, 'person_id' => null, 'created_at' => '2026-03-01 10:00:00', 'updated_at' => '2026-03-01 10:00:00', 'scheduled_at' => $at );
		$rows = array( $row( 1, null ), $row( 2, '2026-03-12 07:00:00' ), $row( 3, '2026-03-10 07:00:00' ) );

		$asc  = $this->service( $rows )->page( 1, 20, array(), 'session', 'asc' );
		$desc = $this->service( $rows )->page( 1, 20, array(), 'session', 'desc' );

		self::assertSame( array( '2026-03-10 07:00:00', '2026-03-12 07:00:00', '' ), array_column( $asc['rows'], 'session' ) );
		self::assertSame( array( '2026-03-12 07:00:00', '2026-03-10 07:00:00', '' ), array_column( $desc['rows'], 'session' ) );
	}
}
