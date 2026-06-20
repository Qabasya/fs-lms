<?php

declare( strict_types=1 );

namespace Unit\DTO\Application;

use Inc\DTO\Application\ApplicationDTO;
use Inc\DTO\Application\ApplicationRecordInputDTO;
use Inc\Enums\ApplicationStatus;
use PHPUnit\Framework\TestCase;

/**
 * Покрывает запись/чтение subject_key (привязка заявки к направлению, Этап 0).
 */
class ApplicationSubjectKeyTest extends TestCase {

	private function makeRecord( ?string $subjectKey ): ApplicationRecordInputDTO {
		return new ApplicationRecordInputDTO(
			status:            ApplicationStatus::PendingParent->value,
			joinCodeHash:      'h',
			joinCodeEnc:       'e',
			joinCodeExpiresAt: '2026-01-01 00:00:00',
			studentDataEnc:    'enc',
			createdAt:         '2026-01-01 00:00:00',
			updatedAt:         '2026-01-01 00:00:00',
			subjectKey:        $subjectKey,
		);
	}

	public function test_record_to_array_includes_subject_key_when_set(): void {
		$data = $this->makeRecord( 'inf' )->toArray();
		self::assertArrayHasKey( 'subject_key', $data );
		self::assertSame( 'inf', $data['subject_key'] );
	}

	public function test_record_to_array_omits_subject_key_when_null_or_empty(): void {
		self::assertArrayNotHasKey( 'subject_key', $this->makeRecord( null )->toArray() );
		self::assertArrayNotHasKey( 'subject_key', $this->makeRecord( '' )->toArray() );
	}

	public function test_read_dto_round_trips_subject_key(): void {
		$row = array(
			'id'          => 5,
			'status'      => ApplicationStatus::PendingParent->value,
			'created_at'  => '2026-01-01 00:00:00',
			'updated_at'  => '2026-01-01 00:00:00',
			'subject_key' => 'math',
		);
		$dto = ApplicationDTO::fromArray( $row );
		self::assertSame( 'math', $dto->subjectKey );
		self::assertSame( 'math', $dto->toArray()['subject_key'] );
	}

	public function test_read_dto_subject_key_null_when_absent(): void {
		$dto = ApplicationDTO::fromArray( array(
			'id'         => 5,
			'status'     => ApplicationStatus::PendingParent->value,
			'created_at' => '2026-01-01 00:00:00',
			'updated_at' => '2026-01-01 00:00:00',
		) );
		self::assertNull( $dto->subjectKey );
	}
}
