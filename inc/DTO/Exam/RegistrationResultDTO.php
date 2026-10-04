<?php

declare( strict_types=1 );

namespace Inc\DTO\Exam;

use Inc\Enums\Exam\ExamRegistrationStatus;

/** Итог операции с записью. Хранится в `exam_operation_keys.result_ref` для повторного ответа по ключу. */
readonly class RegistrationResultDTO {

	/**
	 * @param string[] $warnings Предупреждения, не отменяющие запись (например, `lesson_overlap`).
	 */
	public function __construct(
		public int $registrationId,
		public int $participationId,
		public int $sessionId,
		public ExamRegistrationStatus $status,
		public int $freeSeats,
		public bool $replayed = false,
		public array $warnings = array(),
	) {}

	public function asReplayed(): self {
		return new self( $this->registrationId, $this->participationId, $this->sessionId, $this->status, $this->freeSeats, true, $this->warnings );
	}

	/** @return array<string, mixed> */
	public function toArray(): array {
		return array(
			'registration_id'  => $this->registrationId,
			'participation_id' => $this->participationId,
			'session_id'       => $this->sessionId,
			'status'           => $this->status->value,
			'free_seats'       => $this->freeSeats,
			'replayed'         => $this->replayed,
			'warnings'         => $this->warnings,
		);
	}

	/** @param array<string, mixed> $data */
	public static function fromArray( array $data ): self {
		return new self(
			registrationId : (int) $data['registration_id'],
			participationId: (int) $data['participation_id'],
			sessionId      : (int) $data['session_id'],
			status         : ExamRegistrationStatus::from( (string) $data['status'] ),
			freeSeats      : (int) ( $data['free_seats'] ?? 0 ),
			replayed       : (bool) ( $data['replayed'] ?? false ),
			warnings       : array_values( array_map( 'strval', (array) ( $data['warnings'] ?? array() ) ) ),
		);
	}
}
