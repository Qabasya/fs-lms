<?php
/**
 * Школа и группа участника экзамена для таблиц сотрудника (результаты, статистика).
 *
 * @package FS_LMS
 */

declare( strict_types=1 );

namespace Inc\Services\Exam;

use Inc\DTO\Exam\ExamParticipantDTO;
use Inc\DTO\Exam\ExamParticipationDTO;
use Inc\DTO\Exam\ExamSourceDTO;
use Inc\Repositories\WPDBRepositories\ExamSourceRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;

/**
 * Школа и группа участника экзамена для таблиц сотрудника (результаты, статистика).
 *
 * Школа нашего ученика — из снимка его записи, гостя — из ссылки преподавателя, по которой он записался;
 * самозаписанный гость (открытая запись или без источника) школы не имеет — пустая строка, клиент рисует прочерк.
 * Источники кешируются на время запроса: одна ссылка обычно у десятков участников.
 */
class ExamParticipantProfileService {

	/** @var array<int, ?ExamSourceDTO> */
	private array $sourceCache = array();

	/** Получает записи учеников, источники гостей и группы через принятые слои данных. */
	public function __construct(
		private readonly StudentRecordRepository $studentRecords,
		private readonly ExamSourceRepository $sources,
		private readonly GroupsRepository $groups,
	) {}

	/** Школа участника; пустая строка — школы нет (самозапись, запись без снимка). */
	public function school( ExamParticipationDTO $participation, ExamParticipantDTO $participant ): string {
		if ( null !== $participant->personId ) {
			return trim( (string) ( $this->record( $participant->personId )?->snapshotSchool ?? '' ) );
		}

		$source = $this->source( $participation->sourceId );
		if ( null !== $source && ! $source->isPublic() ) {
			return $source->schoolName;
		}

		// Открытая запись: школу гость мог назвать сам (в снимке участника); служебное имя источника школой не считается.
		$own = trim( (string) ( $participant->schoolName ?? '' ) );

		return null !== $source && $own !== $source->schoolName ? $own : '';
	}

	/** Название группы нашего ученика (действующая запись, иначе последняя); у гостя и без записи — пустая строка. */
	public function groupName( ExamParticipantDTO $participant ): string {
		return $this->group( $participant )['name'];
	}

	/**
	 * Группа нашего ученика одним поиском (запись, затем группа): ID и название; у гостя и без записи — `0` и пустая строка.
	 *
	 * @return array{id: int, name: string}
	 */
	public function group( ExamParticipantDTO $participant ): array {
		$none = array(
			'id'   => 0,
			'name' => '',
		);
		if ( null === $participant->personId ) {
			return $none;
		}

		$record = $this->record( $participant->personId );
		$group  = null !== $record ? $this->groups->findById( $record->groupId ) : null;

		return null !== $group
			? array(
				'id'   => (int) ( $group->id ?? 0 ),
				'name' => (string) ( $group->name ?? '' ),
			)
			: $none;
	}

	private function record( int $personId ): ?\Inc\DTO\Enrollment\StudentRecordDTO {
		return $this->studentRecords->findActiveByStudentFirst( $personId ) ?? ( $this->studentRecords->findByStudent( $personId )[0] ?? null );
	}

	private function source( ?int $sourceId ): ?ExamSourceDTO {
		if ( null === $sourceId ) {
			return null;
		}
		if ( ! array_key_exists( $sourceId, $this->sourceCache ) ) {
			$this->sourceCache[ $sourceId ] = $this->sources->find( $sourceId );
		}

		return $this->sourceCache[ $sourceId ];
	}
}
