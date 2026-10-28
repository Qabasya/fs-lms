<?php

declare( strict_types=1 );

namespace Tests\Unit\Services\Exam;

use Inc\DTO\Exam\ExamParticipantDTO;
use Inc\DTO\Exam\ExamParticipationDTO;
use Inc\Repositories\WPDBRepositories\ExamParticipantRepository;
use Inc\Repositories\WPDBRepositories\ExamParticipationRepository;
use Inc\Services\Exam\ExamTime;
use Inc\Services\Exam\GuestIdentity;
use Inc\Services\Exam\GuestParticipantMaterializer;
use Inc\Services\Security\PiiCryptoService;
use PHPUnit\Framework\TestCase;

/** ФИО гостя по участию: одно место расшифровки для шапки станции и страницы результата. */
class GuestParticipantMaterializerTest extends TestCase {

	private function materializer( ?ExamParticipationDTO $participation, ?ExamParticipantDTO $participant, string $plain = 'Иванов Пётр' ): GuestParticipantMaterializer {
		$participations = $this->createMock( ExamParticipationRepository::class );
		$participations->method( 'find' )->willReturn( $participation );
		$participants = $this->createMock( ExamParticipantRepository::class );
		$participants->method( 'find' )->willReturn( $participant );
		$crypto = $this->createMock( PiiCryptoService::class );
		$crypto->method( 'decrypt' )->willReturn( $plain );

		return new GuestParticipantMaterializer( $participants, $crypto, $this->createMock( GuestIdentity::class ), $this->createStub( ExamTime::class ), $participations );
	}

	private function participation(): ExamParticipationDTO {
		return ExamParticipationDTO::fromArray( array(
			'id' => 4, 'event_id' => 1, 'participant_id' => 9, 'audience' => 'guest', 'transfer_allowed' => 0, 'version' => 1,
			'created_at' => '2026-03-01 00:00:00', 'updated_at' => '2026-03-01 00:00:00',
		) );
	}

	private function participant( ?string $nameEnc ): ExamParticipantDTO {
		return ExamParticipantDTO::fromArray( array( 'id' => 9, 'name_enc' => $nameEnc, 'created_at' => '2026-03-01 00:00:00', 'updated_at' => '2026-03-01 00:00:00' ) );
	}

	public function test_name_of_participation_is_the_decrypted_participant_name(): void {
		$m = $this->materializer( $this->participation(), $this->participant( base64_encode( 'sealed' ) ) );

		self::assertSame( 'Иванов Пётр', $m->nameOfParticipation( 4 ) );
	}

	public function test_name_of_participation_without_name_falls_back_to_guest_number(): void {
		$m = $this->materializer( $this->participation(), $this->participant( null ) );

		self::assertSame( 'Гость #9', $m->nameOfParticipation( 4 ) );
	}

	public function test_name_of_missing_participation_is_empty(): void {
		self::assertSame( '', $this->materializer( null, null )->nameOfParticipation( 4 ) );
		self::assertSame( '', $this->materializer( $this->participation(), null )->nameOfParticipation( 4 ) );
	}
}
