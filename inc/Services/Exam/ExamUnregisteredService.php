<?php
/**
 * Ученики направления, ещё не записавшиеся на проведение экзамена.
 *
 * @package FS_LMS
 */

declare( strict_types=1 );

namespace Inc\Services\Exam;

use Inc\Enums\Exam\ExamRegistrationStatus;
use Inc\Enums\Log\ErrorCode;
use Inc\Repositories\WPDBRepositories\ExamEventRepository;
use Inc\Repositories\WPDBRepositories\ExamRegistrationRepository;
use Inc\Repositories\WPDBRepositories\ExamParticipationRepository;
use Inc\Repositories\WPDBRepositories\ExamSessionRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Shared\CodedException;

/**
 * Ученики направления, ещё не записавшиеся на проведение экзамена.
 *
 * Направление — предмет проведения (информатика ЕГЭ или ОГЭ): аудиторию задаёт {@see ExamAudienceResolver}, как и для записи.
 * Записавшимся считается ученик, у которого по проведению есть хотя бы одна не отменённая запись (в том числе неявка);
 * отменившие запись и не записывавшиеся попадают в список. Гостей в нём нет — у них нет зачисления.
 */
class ExamUnregisteredService {

	/** Получает аудиторию, участия и записи проведения и данные зачисления ученика. */
	public function __construct(
		private readonly ExamSessionRepository $sessions,
		private readonly ExamEventRepository $events,
		private readonly ExamAccessGuard $guard,
		private readonly ExamAudienceResolver $audience,
		private readonly ExamParticipationRepository $participations,
		private readonly ExamRegistrationRepository $registrations,
		private readonly StudentRecordRepository $records,
		private readonly GroupsRepository $groups,
	) {}

	/**
	 * Незаписавшиеся ученики проведения сеанса, по алфавиту.
	 *
	 * @return list<array{person_id: int, name: string, group: string, school: string}>
	 *
	 * @throws CodedException `ExamAccess` — сеанса нет или проведение не принадлежит сотруднику.
	 */
	public function rows( int $actorUserId, int $sessionId ): array {
		$session = $this->sessions->find( $sessionId );
		$event   = null !== $session ? $this->events->find( $session->eventId ) : null;
		if ( null === $session || null === $event || ! $this->guard->canManageEvent( $actorUserId, $event ) ) {
			throw new CodedException( ErrorCode::ExamAccess, 'Сеанс не найден.' );
		}

		$rows = array();
		foreach ( $this->audience->studentPersonIds( $event->subjectKey ) as $personId ) {
			if ( $this->isRegistered( $event->id, $personId ) ) {
				continue;
			}
			$record = $this->records->findActiveByStudentFirst( $personId );
			if ( null === $record ) {
				continue;
			}
			$group  = $this->groups->findById( $record->groupId );
			$rows[] = array(
				'person_id' => $personId,
				'name'      => trim( $record->snapshotLastName . ' ' . $record->snapshotFirstName ),
				'group'     => (string) ( $group->name ?? '' ),
				'school'    => trim( (string) $record->snapshotSchool ),
			);
		}
		usort( $rows, static fn ( array $a, array $b ): int => strcmp( mb_strtolower( $a['name'] ), mb_strtolower( $b['name'] ) ) );

		return $rows;
	}

	/**
	 * Строки выгрузки и печати: те же поля, что у участников (ФИО, источник, сеанс, статус), баллов нет.
	 * С `$personIds` остаются только выбранные фильтрами ученики — и только из действительно незаписавшихся.
	 *
	 * @param list<int> $personIds Пусто — все незаписавшиеся.
	 *
	 * @return list<array{name: string, source: string, session: string, status: string, primary: string, secondary: string}>
	 */
	public function exportRows( int $actorUserId, int $sessionId, array $personIds = array() ): array {
		$rows = $this->rows( $actorUserId, $sessionId );
		if ( array() !== $personIds ) {
			$rows = array_values( array_filter( $rows, static fn ( array $r ): bool => in_array( $r['person_id'], $personIds, true ) ) );
		}

		return array_map(
			static fn ( array $r ): array => array(
				'name'      => $r['name'],
				'source'    => $r['group'],
				'session'   => '',
				'status'    => 'Не записан',
				'primary'   => '',
				'secondary' => '',
			),
			$rows
		);
	}

	/** Есть ли у ученика по проведению не отменённая запись. */
	private function isRegistered( int $eventId, int $personId ): bool {
		$participation = $this->participations->findByEventAndPerson( $eventId, $personId );
		if ( null === $participation ) {
			return false;
		}
		foreach ( $this->registrations->findByParticipation( $participation->id ) as $registration ) {
			if ( ExamRegistrationStatus::Cancelled->value !== $registration->status ) {
				return true;
			}
		}

		return false;
	}
}
