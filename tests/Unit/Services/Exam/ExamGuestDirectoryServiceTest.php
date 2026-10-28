<?php

declare( strict_types=1 );

namespace Tests\Unit\Services\Exam;

use Inc\DTO\Subject\SubjectDTO;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Repositories\WPDBRepositories\ExamParticipantRepository;
use Inc\Services\Exam\ExamGuestDirectoryService;
use Inc\Services\Exam\ExamTime;
use Inc\Services\Exam\GuestParticipantMaterializer;
use PHPUnit\Framework\TestCase;

class ExamGuestDirectoryServiceTest extends TestCase {

	private function service( array $rows, array $options = array(), array $schoolsByEvent = array() ): ExamGuestDirectoryService {
		$participants = $this->createMock( ExamParticipantRepository::class );
		$participants->method( 'listGuestRows' )->willReturn( $rows );
		$participants->method( 'guestSessionOptions' )->willReturn( $options );
		$participants->method( 'guestSchools' )->willReturnCallback( static fn ( int $eventId = 0 ): array => $schoolsByEvent[ $eventId ] ?? array() );
		$guestData = $this->createMock( GuestParticipantMaterializer::class );
		$guestData->method( 'decryptName' )->willReturn( 'Иванов Пётр' );
		$guestData->method( 'decryptContacts' )->willReturn( array( 'phone' => '+79990001122', 'messenger' => '@ivan' ) );
		$time = $this->createMock( ExamTime::class );
		$time->method( 'toLocal' )->willReturnCallback( static fn ( string $u ): string => $u );

		$subjects = $this->createMock( SubjectRepository::class );
		$subjects->method( 'getByKey' )->willReturnCallback(
			static fn ( string $key ): ?SubjectDTO => 'inf' === $key ? new SubjectDTO( 'inf', 'Информатика ЕГЭ' ) : null
		);

		return new ExamGuestDirectoryService( $participants, $guestData, $time, $this->createMock( \Inc\Services\Exam\ApplyPrefillLinkService::class ), $subjects );
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

	/** @return list<array<string, mixed>> Два проведения: информатика и неизвестный предмет, у каждого по сеансу. */
	private function options(): array {
		return array(
			array( 'event_id' => 2, 'event_title' => 'Пробный', 'subject_key' => 'inf', 'session_id' => 20, 'scheduled_at' => '2026-03-12 07:00:00' ),
			array( 'event_id' => 1, 'event_title' => 'Зимний', 'subject_key' => 'math', 'session_id' => 10, 'scheduled_at' => '2026-03-10 07:00:00' ),
		);
	}

	public function test_event_option_is_labelled_with_the_subject(): void {
		$options = $this->service( array(), $this->options() )->filterOptions();

		self::assertSame( 'Пробный · Информатика ЕГЭ', $options['events'][2] );
		self::assertSame( 'Зимний · math', $options['events'][1], 'предмета нет в справочнике — подпись по ключу' );
	}

	public function test_sessions_are_all_listed_without_event_and_narrowed_with_it(): void {
		$service = $this->service( array(), $this->options() );

		self::assertSame( array( 20, 10 ), array_keys( $service->filterOptions()['sessions'] ) );
		self::assertSame( array( 20 ), array_keys( $service->filterOptions( 2 )['sessions'] ) );
	}

	public function test_schools_are_narrowed_by_the_chosen_event(): void {
		$service = $this->service( array(), $this->options(), array( 0 => array( 'А', 'Б' ), 2 => array( 'Б' ) ) );

		self::assertSame( array( 'А', 'Б' ), array_keys( $service->filterOptions()['schools'] ) );
		self::assertSame( array( 'Б' ), array_keys( $service->filterOptions( 2 )['schools'] ) );
	}
}
