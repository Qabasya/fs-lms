<?php
/**
 * Имя участника для доски, отчёта и уведомлений; расшифровка гостя остаётся в materializer.
 *
 * @package FS_LMS
 */

declare( strict_types=1 );

namespace Inc\Services\Exam;

use Inc\DTO\Exam\ExamParticipantDTO;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;

/** Имя участника для доски, отчёта и уведомлений; расшифровка гостя остаётся в materializer. */
class ExamParticipantNameService {

	/** Получает записи учеников и расшифровку гостей через принятые слои данных. */
	public function __construct(
		private readonly StudentRecordRepository $studentRecords,
		private readonly GuestParticipantMaterializer $guestData,
	) {}

	/** Имя из активного/последнего снимка ученика или гостевых данных; при утрате записи — номер. */
	public function participantName( ExamParticipantDTO $participant ): string {
		if ( null === $participant->personId ) {
			return $this->guestData->displayName( $participant ) ?? sprintf( 'Гость #%d', $participant->id );
		}

		$record = $this->studentRecords->findActiveByStudentFirst( $participant->personId ) ?? ( $this->studentRecords->findByStudent( $participant->personId )[0] ?? null );
		if ( null === $record ) {
			return sprintf( 'Ученик #%d', $participant->personId );
		}

		return trim( $record->snapshotLastName . ' ' . $record->snapshotFirstName );
	}
}
