<?php

declare( strict_types=1 );

namespace Inc\Services\Exam;

use Inc\DTO\Assessment\AttemptDTO;
use Inc\DTO\Exam\ExamEventDTO;
use Inc\DTO\Exam\ExamParticipantDTO;
use Inc\DTO\Exam\ExamParticipationDTO;
use Inc\DTO\Exam\ExamRegistrationDTO;
use Inc\DTO\Exam\ExamSessionDTO;
use Inc\Enums\Assessment\AttemptStatus;
use Inc\Enums\Exam\ExamAudience;
use Inc\Enums\Exam\ExamConductAction;
use Inc\Enums\Exam\ExamResultStatus;
use Inc\Enums\Exam\ExamProgress;
use Inc\Enums\Exam\ExamRegistrationStatus;
use Inc\Enums\Exam\ExamSessionStatus;
use Inc\Enums\Exam\ExamTokenPurpose;
use Inc\Enums\Log\ErrorCode;
use Inc\Managers\Person\UserManager;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Repositories\WPDBRepositories\ExamEventRepository;
use Inc\Repositories\WPDBRepositories\ExamParticipantRepository;
use Inc\Repositories\WPDBRepositories\ExamParticipationRepository;
use Inc\Repositories\WPDBRepositories\ExamRegistrationRepository;
use Inc\Repositories\WPDBRepositories\ExamSessionRepository;
use Inc\Repositories\WPDBRepositories\ExamSourceRepository;
use Inc\Repositories\WPDBRepositories\RoomRepository;
use Inc\Shared\CodedException;

/** Доска сеанса и строки выгрузки: чтение, состояния и действия сотрудника. */
class ExamConductViewService {

	/** Окно поиска сеанса «по умолчанию»: от вчера на три месяца вперёд. */
	private const DEFAULT_LOOKBEHIND_DAYS = 1;
	private const DEFAULT_LOOKAHEAD_DAYS  = 90;

	public function __construct(
		private readonly ExamEventRepository $events,
		private readonly ExamSessionRepository $sessions,
		private readonly ExamRegistrationRepository $registrations,
		private readonly ExamParticipationRepository $participations,
		private readonly ExamParticipantRepository $participants,
		private readonly AssessmentAttemptRepository $attempts,
		private readonly ExamResultStatusService $resultStates,
		private readonly ExamSourceRepository $sources,
		private readonly ExamParticipantNameService $names,
		private readonly RoomRepository $rooms,
		private readonly ExamRoomService $roomService,
		private readonly ExamScoreService $scores,
		private readonly ExamAccessGuard $guard,
		private readonly ExamTime $time,
		private readonly ExamPlanService $plans,
		private readonly ExamAccessTokenService $tokens,
		private readonly ExamGuestBoardService $guestBoard,
		private readonly UserManager $users,
		private readonly ExamEventViewService $eventView,
	) {}

	/**
	 * Доска сеанса.
	 *
	 * @return array{session: array<string, mixed>, tiles: array<string, int>, rows: list<array<string, mixed>>, warnings: list<string>, sessions: list<array<string, mixed>>, rooms: list<array<string, mixed>>, sources: list<array{id: int, label: string}>, holds: list<array<string, mixed>>}
	 *
	 * @throws CodedException `ExamAccess` — сеанса нет или проведение не принадлежит сотруднику.
	 */
	public function sessionBoard( int $actorUserId, int $sessionId ): array {
		$session = $this->sessions->find( $sessionId );
		$event   = null !== $session ? $this->events->find( $session->eventId ) : null;
		if ( null === $session || null === $event || ! $this->guard->canManageEvent( $actorUserId, $event ) ) {
			throw new CodedException( ErrorCode::ExamAccess, 'Сеанс не найден.' );
		}

		$nowLocal = $this->time->nowLocal();
		$rows     = $this->rowsFor( $session, $event, $nowLocal );

		return array(
			'session'  => $this->sessionPayload( $session, $event ),
			'tiles'    => $this->tiles( $rows ),
			'rows'     => $rows,
			'warnings' => $this->warnings( $session, $rows ),
			'sessions' => $this->eventView->siblings( $event ),
			'rooms'    => $this->plans->roomsFor( $event->subjectKey ),
			'sources'  => $this->eventView->sourceOptions( $event->id ),
			'holds'    => $this->guestBoard->holdsOf( $session ),
		);
	}

	/**
	 * Сеанс для экрана без параметров: идущий сейчас, иначе ближайший будущий, иначе последний прошедший.
	 *
	 * @return int|null null — сеансов в доступных проведениях нет.
	 */
	public function defaultSessionId( int $actorUserId ): ?int {
		$nowUtc = $this->time->nowUtc();
		$all    = $this->guard->isGlobal( $actorUserId );
		$list   = $this->sessions->listForTeacherBetween(
			$actorUserId,
			$all,
			$this->time->addMinutes( $nowUtc, -self::DEFAULT_LOOKBEHIND_DAYS * 1440 ),
			$this->time->addMinutes( $nowUtc, self::DEFAULT_LOOKAHEAD_DAYS * 1440 )
		);

		$next = null;
		$last = null;
		foreach ( $list as $row ) {
			$id    = (int) $row['id'];
			$start = (string) $row['scheduled_at'];
			$end   = (string) $row['planned_end_at'];
			if ( $start <= $nowUtc && $nowUtc <= $end ) {
				return $id;
			}
			if ( $start > $nowUtc ) {
				$next ??= $id;
			} else {
				$last = $id;
			}
		}

		return $next ?? $last;
	}

	/**
	 * Строки списка участников для CSV и печати (8.9): ФИО, источник, сеанс, статус, баллы. **Контактов нет и быть не может** —
	 * телефон и мессенджер участника сюда не читаются вовсе.
	 *
	 * Выборка — сеанс (`session_id`) или конкретные участия (`participation_ids`): выгрузка выбранных гостей не тянет всех.
	 * Проведения, на которые у пользователя нет доступа, молча пропускаются (по участиям) или отказывают целиком (по сеансу).
	 *
	 * @param array<string, mixed> $context `session_id` или `participation_ids`.
	 *
	 * @return list<array{name: string, source: string, session: string, status: string, primary: string, secondary: string}>
	 *
	 * @throws CodedException
	 */
	public function exportRows( int $actorUserId, array $context ): array {
		$rows = array();

		if ( (int) ( $context['session_id'] ?? 0 ) > 0 ) {
			$board   = $this->sessionBoard( $actorUserId, (int) $context['session_id'] );
			$session = $this->sessionLabel( $board['session']['date'], $board['session']['time_start'] );
			foreach ( $board['rows'] as $row ) {
				$rows[] = $this->exportRow( $row, $session );
			}

			return $rows;
		}

		foreach ( array_unique( array_map( 'intval', (array) ( $context['participation_ids'] ?? array() ) ) ) as $participationId ) {
			$participation = $this->participations->find( $participationId );
			$event         = null !== $participation ? $this->events->find( $participation->eventId ) : null;
			if ( null === $participation || null === $event || ! $this->guard->canManageEvent( $actorUserId, $event ) ) {
				continue;
			}

			$registration = $this->registrationOf( $participation );
			$session      = null !== $registration ? $this->sessions->find( $registration->sessionId ) : null;
			if ( null === $registration || null === $session ) {
				continue;
			}

			$row = $this->rowFor( $registration, $event, array(), $this->time->nowLocal() );
			if ( null !== $row ) {
				$start  = $this->time->toLocal( $session->scheduledAt );
				$rows[] = $this->exportRow( $row, $this->sessionLabel( substr( $start, 0, 10 ), substr( $start, 11, 5 ) ) );
			}
		}

		return $rows;
	}

	/** Запись участия для выгрузки: действующая, иначе та, по которой начата попытка. */
	private function registrationOf( ExamParticipationDTO $participation ): ?ExamRegistrationDTO {
		if ( null !== $participation->activeRegistrationId ) {
			return $this->registrations->find( $participation->activeRegistrationId );
		}
		$attempt = null !== $participation->currentAttemptId ? $this->attempts->find( $participation->currentAttemptId ) : null;

		return null !== $attempt && null !== $attempt->examRegistrationId ? $this->registrations->find( $attempt->examRegistrationId ) : null;
	}

	private function sessionLabel( string $date, string $time ): string {
		return $date . ' ' . $time;
	}

	/**
	 * @param array<string, mixed> $row Строка доски.
	 *
	 * @return array{name: string, source: string, session: string, status: string, primary: string, secondary: string}
	 */
	private function exportRow( array $row, string $session ): array {
		$score     = is_array( $row['score'] ?? null ) ? $row['score'] : null;
		$secondary = '';
		if ( null !== $score && empty( $score['pending'] ) ) {
			if ( null !== $score['secondary'] ) {
				$secondary = $score['secondary'] . ' / ' . $score['secondary_max'];
			} elseif ( null !== $score['grade'] ) {
				$secondary = 'оценка ' . $score['grade'];
			}
		}

		return array(
			'name'      => (string) $row['name'],
			'source'    => (string) $row['source'],
			'session'   => $session,
			'status'    => ExamRegistrationStatus::Cancelled->value === $row['registration_status'] ? 'Запись отменена' : (string) $row['progress_label'],
			'primary'   => null !== $score ? $score['primary'] . ' / ' . $score['primary_max'] : '',
			'secondary' => $secondary,
		);
	}

	/**
	 * Права на строку: единственное место, где решается, какие действия показать (клиент свои не придумывает).
	 *
	 * @param list<ExamSessionDTO> $otherOpenSessions Другие сеансы проведения, не завершившиеся и со свободным местом.
	 *
	 * @return list<string>
	 */
	public function rowActions( ExamRegistrationDTO $registration, ?AttemptDTO $attempt, array $otherOpenSessions, ?ExamParticipationDTO $participation = null ): array {
		$active  = 1 === $registration->activeSlot && ExamRegistrationStatus::Confirmed->value === $registration->status;
		$actions = array();
		if ( null !== $attempt ) {
			$actions = AttemptStatus::InProgress === $attempt->status ? array( ExamConductAction::Extend->value ) : array( ExamConductAction::OpenWork->value );
		} elseif ( $active ) {
			$actions = array( ExamConductAction::Cancel->value );
			if ( array() !== $otherOpenSessions ) {
				$actions[] = ExamConductAction::Transfer->value;
			}
			$actions[] = ExamConductAction::Arrival->value;
		}

		// Гость: допуск после личной проверки на площадке; ссылка входа — только допущенному (и пока не сдал).
		if ( null !== $participation && ExamAudience::Guest->value === $participation->audience && ( $active || ( null !== $attempt && AttemptStatus::InProgress === $attempt->status ) ) ) {
			if ( null === $attempt ) {
				$actions[] = ExamConductAction::Admit->value;
			}
			if ( null !== $participation->admittedAt ) {
				$actions[] = ExamConductAction::EntryLink->value;
				if ( $this->tokens->hasActive( ExamTokenPurpose::Entry, $participation->id ) ) {
					$actions[] = ExamConductAction::MarkEntryPassed->value;
				}
			}
		}

		// Удаление данных гостя по его запросу: ручное действие, доступно строке любого гостя, пока данные не обезличены.
		if ( null !== $participation && ExamAudience::Guest->value === $participation->audience ) {
			$actions[] = ExamConductAction::Anonymize->value;
		}

		// Ссылка результата — гостю со сданной работой (выдача и перевыпуск); отзыв — когда ссылка уже выдана (флаг в строке).
		if ( null !== $participation && ExamAudience::Guest->value === $participation->audience && null !== $attempt && AttemptStatus::InProgress !== $attempt->status ) {
			$actions[] = ExamConductAction::ResultLink->value;
			if ( $this->tokens->hasActive( ExamTokenPurpose::Result, $participation->id ) ) {
				$actions[] = ExamConductAction::MarkResultPassed->value;
				$actions[] = ExamConductAction::RevokeResultLink->value;
			}
		}

		return $actions;
	}

	/** «Передана 10.10 15:00, Иванов» — или null, пока сотрудник не отметил вручную. */
	private function passedLabel( ?\Inc\DTO\Exam\ExamAccessTokenDTO $token ): ?string {
		if ( null === $token || null === $token->passedAt ) {
			return null;
		}
		$local = $this->time->toLocal( $token->passedAt );
		$user  = null !== $token->passedByUserId ? $this->users->find( $token->passedByUserId ) : null;

		return sprintf( 'Передана %s.%s %s%s', substr( $local, 8, 2 ), substr( $local, 5, 2 ), substr( $local, 11, 5 ), $user ? ', ' . $user->display_name : '' );
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function rowsFor( ExamSessionDTO $session, ExamEventDTO $event, string $nowLocal ): array {
		$registrations = $this->registrations->listBySession(
			$session->id,
			array( ExamRegistrationStatus::Confirmed, ExamRegistrationStatus::Missed, ExamRegistrationStatus::Cancelled )
		);

		// Действующая запись участия в этом сеансе заменяет его же исторические отмены: строка одна на участие.
		$hasConfirmed = array();
		foreach ( $registrations as $registration ) {
			if ( ExamRegistrationStatus::Confirmed->value === $registration->status ) {
				$hasConfirmed[ $registration->participationId ] = true;
			}
		}

		$others = $this->openSiblings( $session );
		$rows   = array();
		foreach ( $registrations as $registration ) {
			if ( ExamRegistrationStatus::Confirmed->value !== $registration->status && isset( $hasConfirmed[ $registration->participationId ] ) ) {
				continue;
			}
			$row = $this->rowFor( $registration, $event, $others, $nowLocal );
			if ( null !== $row ) {
				$rows[] = $row;
			}
		}

		return $rows;
	}

	/**
	 * @param list<ExamSessionDTO> $others
	 *
	 * @return array<string, mixed>|null
	 */
	private function rowFor( ExamRegistrationDTO $registration, ExamEventDTO $event, array $others, string $nowLocal ): ?array {
		$participation = $this->participations->find( $registration->participationId );
		$participant   = null !== $participation ? $this->participants->find( $participation->participantId ) : null;
		if ( null === $participation || null === $participant ) {
			return null;
		}

		$audience = ExamAudience::from( $participation->audience );
		$attempt  = $this->attemptOf( $participation, $registration );
		$progress = $this->progressOf( $registration, $attempt );
		$result   = $this->resultStatus( $attempt, $audience );
		$source   = null !== $participation->sourceId ? $this->sources->find( $participation->sourceId ) : null;

		return array(
			'registration_id'     => $registration->id,
			'participation_id'    => $participation->id,
			'participant_id'      => $participant->id,
			'name'                => $this->participantName( $participant ),
			'audience'            => $audience->value,
			'audience_label'      => $audience->label(),
			'source'              => $source->schoolName ?? '',
			'registration_status' => $registration->status,
			// Гость: четыре независимых состояния — оплата, запись, допуск (`admitted_at`), попытка (`progress`); у ученика оплаты нет.
			'payment'             => ExamAudience::Guest === $audience ? $this->guestBoard->paymentOf( $participation->id ) : null,
			'arrived_at'          => null !== $registration->arrivedAt ? $this->time->toLocal( $registration->arrivedAt ) : null,
			'progress'            => $progress->value,
			'progress_label'      => $progress->label(),
			'started_at'          => $attempt?->startedAt,
			'deadline_at'         => $attempt?->deadlineAt,
			'seconds_left'        => $this->secondsLeft( $attempt, $nowLocal ),
			'attempt_id'          => $attempt?->id,
			'result_version'      => $attempt?->resultVersion,
			'score'               => $this->scoreOf( $attempt, $event ),
			'result_status'       => $result,
			'result_status_label' => $this->resultLabel( $result, $audience ),
			'actions'             => $this->rowActions( $registration, $attempt, $others, $participation ),
			'admitted_at'         => null !== $participation->admittedAt ? $this->time->toLocal( $participation->admittedAt ) : null,
			'entry_link_issued'   => ExamAudience::Guest === $audience && $this->tokens->hasActive( ExamTokenPurpose::Entry, $participation->id ),
			'entry_link_passed'   => $this->passedLabel( ExamAudience::Guest === $audience ? $this->tokens->activeToken( ExamTokenPurpose::Entry, $participation->id ) : null ),
			'result_link_passed'  => $this->passedLabel( ExamAudience::Guest === $audience ? $this->tokens->activeToken( ExamTokenPurpose::Result, $participation->id ) : null ),
			'result_link_issued'  => ExamAudience::Guest === $audience && $this->tokens->hasActive( ExamTokenPurpose::Result, $participation->id ),
		);
	}

	/** Попытка строки: только если она начата именно по этой записи (после переноса попытка принадлежит новой). */
	private function attemptOf( ExamParticipationDTO $participation, ExamRegistrationDTO $registration ): ?AttemptDTO {
		if ( null === $participation->currentAttemptId ) {
			return null;
		}
		$attempt = $this->attempts->find( $participation->currentAttemptId );

		return ( null !== $attempt && $attempt->examRegistrationId === $registration->id ) ? $attempt : null;
	}

	private function progressOf( ExamRegistrationDTO $registration, ?AttemptDTO $attempt ): ExamProgress {
		if ( null !== $attempt ) {
			return AttemptStatus::InProgress === $attempt->status ? ExamProgress::InProgress : ExamProgress::Submitted;
		}

		return ExamRegistrationStatus::Missed->value === $registration->status ? ExamProgress::Missed : ExamProgress::NotStarted;
	}

	private function secondsLeft( ?AttemptDTO $attempt, string $nowLocal ): ?int {
		if ( null === $attempt || AttemptStatus::InProgress !== $attempt->status ) {
			return null;
		}

		return max( 0, $this->time->secondsUntil( $nowLocal, $attempt->deadlineAt ) );
	}

	/**
	 * Итог сотруднику виден сразу: баллы показываются и до утверждения.
	 *
	 * @return array<string, mixed>|null
	 */
	private function scoreOf( ?AttemptDTO $attempt, ExamEventDTO $event ): ?array {
		if ( null === $attempt || AttemptStatus::InProgress === $attempt->status ) {
			return null;
		}

		return $this->scores->summarize( $attempt, $event );
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 *
	 * @return array{registered: int, started: int, submitted: int, missed: int, pending: int}
	 */
	private function tiles( array $rows ): array {
		$tiles = array( 'registered' => 0, 'started' => 0, 'submitted' => 0, 'missed' => 0, 'pending' => 0 );
		foreach ( $rows as $row ) {
			if ( ExamRegistrationStatus::Cancelled->value === $row['registration_status'] ) {
				continue;
			}
			++$tiles['registered'];
			if ( ExamProgress::Missed->value === $row['progress'] ) {
				++$tiles['missed'];
			}
			if ( in_array( $row['progress'], array( ExamProgress::InProgress->value, ExamProgress::Submitted->value ), true ) ) {
				++$tiles['started'];
			}
			if ( ExamProgress::Submitted->value === $row['progress'] ) {
				++$tiles['submitted'];
			}
			if ( ExamResultStatus::PendingReview->value === $row['result_status'] ) {
				++$tiles['pending'];
			}
		}

		return $tiles;
	}

	/**
	 * Предупреждение о позднем старте: сам поздний личный дедлайн идущих попыток заходит на занятие или экзамен в кабинете.
	 * Только информация, ничего не блокируется.
	 *
	 * @param list<array<string, mixed>> $rows
	 *
	 * @return list<string>
	 */
	private function warnings( ExamSessionDTO $session, array $rows ): array {
		$latest = null;
		foreach ( $rows as $row ) {
			if ( ExamProgress::InProgress->value === $row['progress'] && is_string( $row['deadline_at'] ) && ( null === $latest || $row['deadline_at'] > $latest ) ) {
				$latest = $row['deadline_at'];
			}
		}
		if ( null === $latest ) {
			return array();
		}

		$warnings = array();
		foreach ( $this->roomService->lateStartConflicts( $session, $this->time->toUtc( $latest ) ) as $conflict ) {
			$warnings[] = sprintf(
				'После планового конца в кабинете стоит %s в %s.',
				'lesson' === $conflict['kind'] ? 'занятие «' . $conflict['title'] . '»' : 'экзамен «' . $conflict['title'] . '»',
				substr( $conflict['start'], 11, 5 )
			);
		}

		return $warnings;
	}

	/** @return array<string, mixed> */
	private function sessionPayload( ExamSessionDTO $session, ExamEventDTO $event ): array {
		$start = $this->time->toLocal( $session->scheduledAt );
		$end   = $this->time->toLocal( $session->plannedEndAt );

		return array(
			'id'          => $session->id,
			'event_id'    => $event->id,
			'event_title' => $event->title,
			'date'        => substr( $start, 0, 10 ),
			'time_start'  => substr( $start, 11, 5 ),
			'time_end'    => substr( $end, 11, 5 ),
			'room_id'     => $session->roomId,
			'room_name'   => $this->rooms->find( $session->roomId )->name ?? '',
			'capacity'    => $session->capacity,
			'occupied'    => $session->occupiedCount,
			'status'      => $session->status,
			'is_locked'   => $session->isLocked(),
			'version'     => $session->version,
		);
	}

	/**
	 * Другие сеансы проведения, куда ещё можно перенести запись: открыты, не закончились, есть место.
	 *
	 * @return list<ExamSessionDTO>
	 */
	private function openSiblings( ExamSessionDTO $session ): array {
		$nowUtc = $this->time->nowUtc();

		return array_values( array_filter(
			$this->sessions->findByEvent( $session->eventId ),
			static fn ( ExamSessionDTO $other ): bool => $other->id !== $session->id
				&& ExamSessionStatus::Open->value === $other->status
				&& $other->plannedEndAt > $nowUtc
				&& $other->freeSeats() > 0
		) );
	}

	/**
	 * Состояние результата строки.
	 *
	 * Гостю утверждение не требуется: готовая работа для него равнозначна утверждённой («Результат выдан»).
	 */
	private function resultStatus( ?AttemptDTO $attempt, ExamAudience $audience ): string {
		return $this->resultStates->status( $attempt, $audience )->value;
	}

	private function resultLabel( string $result, ExamAudience $audience ): string {
		return ExamResultStatus::from( $result )->label( $audience );
	}

	/** ФИО участника для таблицы сотрудника. */
	private function participantName( ExamParticipantDTO $participant ): string {
		return $this->names->participantName( $participant );
	}
}
