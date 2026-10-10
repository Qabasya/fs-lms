<?php

declare( strict_types=1 );

namespace Inc\Services\Exam;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Assessment\AttemptDTO;
use Inc\DTO\Log\Events\EntityChangedEvent;
use Inc\DTO\Exam\ExamEventDTO;
use Inc\DTO\Exam\ExamParticipantDTO;
use Inc\DTO\Exam\ExamParticipationDTO;
use Inc\DTO\Exam\ExamRegistrationDTO;
use Inc\DTO\Exam\ExamSessionDTO;
use Inc\Enums\Assessment\AttemptStatus;
use Inc\Enums\Access\Capability;
use Inc\Enums\Exam\ExamAudience;
use Inc\Enums\Exam\ExamProgress;
use Inc\Enums\Exam\ExamRegistrationStatus;
use Inc\Enums\Exam\ExamSessionStatus;
use Inc\Enums\Exam\ExamTokenPurpose;
use Inc\Enums\Log\EntityType;
use Inc\Enums\Log\ErrorCode;
use Inc\Enums\Log\LogEvent;
use Inc\Enums\Log\OperationType;
use Inc\Enums\Wp\PageRoutes;
use Inc\Repositories\WPDBRepositories\AssessmentAnswerRepository;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Repositories\WPDBRepositories\ExamEventRepository;
use Inc\Repositories\WPDBRepositories\ExamParticipantRepository;
use Inc\Repositories\WPDBRepositories\ExamParticipationRepository;
use Inc\Repositories\WPDBRepositories\ExamRegistrationRepository;
use Inc\Repositories\WPDBRepositories\ExamSessionRepository;
use Inc\Repositories\WPDBRepositories\ExamSourceRepository;
use Inc\Repositories\WPDBRepositories\RoomRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Shared\PluginConfig;
use Inc\Shared\CodedException;

/**
 * Доска сеанса экзамена для сотрудника (8.1): кто записан, кто начал, кто сдал, кто не явился, сколько осталось.
 *
 * Сервис только читает и считает вычисляемые состояния; изменения идут через {@see ExamRegistrationService}
 * (отмена, перенос), {@see ExamAttemptService} (продление) и собственную отметку прихода. Доступ — `canManageEvent()`
 * по проведению сеанса: чужое проведение отвечает как несуществующее.
 *
 * **Имена.** Ученик — снимок ФИО из `student_records` (как на остальных экранах преподавателя), не расшифрованный документ;
 * гость до этапа 11a — «Гость #id». Контакты в таблицу не выводятся никогда.
 *
 * **Время.** Сеанс и дедлайн в ответе — местные; «остаток» считает сервер от `ExamTime::nowLocal()`, клиент только тикает.
 */
class ExamConductService {

	/** Окно поиска сеанса «по умолчанию»: от вчера на три месяца вперёд. */
	private const DEFAULT_LOOKBEHIND_DAYS = 1;
	private const DEFAULT_LOOKAHEAD_DAYS  = 90;

	public const RESULT_NONE           = 'none';
	public const RESULT_PENDING_REVIEW = 'pending_review';
	public const RESULT_READY          = 'ready';
	public const RESULT_APPROVED       = 'approved';

	private const RESULT_LABELS = array(
		self::RESULT_NONE           => 'Нет работы',
		self::RESULT_PENDING_REVIEW => 'Ожидает проверки',
		self::RESULT_READY          => 'Готова к утверждению',
		self::RESULT_APPROVED       => 'Утверждена',
	);

	public const ACTION_CANCEL    = 'cancel';
	public const ACTION_TRANSFER  = 'transfer';
	public const ACTION_EXTEND    = 'extend';
	public const ACTION_ARRIVAL   = 'arrival';
	public const ACTION_OPEN_WORK = 'open_work';
	public const ACTION_ADMIT      = 'admit';
	public const ACTION_ENTRY_LINK = 'entry_link';
	public const ACTION_RESULT_LINK = 'result_link';
	public const ACTION_ANONYMIZE = 'anonymize';
	public const ACTION_MARK_ENTRY_PASSED = 'mark_entry_passed';
	public const ACTION_MARK_RESULT_PASSED = 'mark_result_passed';
	public const ACTION_REVOKE_RESULT_LINK = 'revoke_result_link';

	public function __construct(
		private readonly ExamEventRepository $events,
		private readonly ExamSessionRepository $sessions,
		private readonly ExamRegistrationRepository $registrations,
		private readonly ExamParticipationRepository $participations,
		private readonly ExamParticipantRepository $participants,
		private readonly AssessmentAttemptRepository $attempts,
		private readonly AssessmentAnswerRepository $answers,
		private readonly ExamSourceRepository $sources,
		private readonly StudentRecordRepository $studentRecords,
		private readonly RoomRepository $rooms,
		private readonly ExamRoomService $roomService,
		private readonly ExamScoreService $scores,
		private readonly ExamAccessGuard $guard,
		private readonly ExamTime $time,
		private readonly ExamPlanService $plans,
		private readonly ExamReviewProjection $reviews,
		private readonly ExamAccessTokenService $tokens,
		private readonly GuestParticipantMaterializer $guestData,
		private readonly PluginConfig $config,
		private readonly LogEventDispatcherInterface $logEvents,
		private readonly ExamGuestBoardService $guestBoard,
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
			'sessions' => $this->siblings( $event ),
			'rooms'    => $this->plans->roomsFor( $event->subjectKey ),
			'sources'  => $this->sourceOptions( $event->id ),
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
	 * Вправе ли пользователь работать с экзаменной попыткой (проверять, оценивать, утверждать, открывать): единственное решение для
	 * коллбеков «Работ». Не экзаменная попытка сюда не относится — для неё отвечает прежняя проверка группы.
	 */
	public function canManageAttempt( int $userId, AttemptDTO $attempt ): bool {
		if ( null === $attempt->examParticipationId ) {
			return false;
		}
		$participation = $this->participations->find( $attempt->examParticipationId );
		$event         = null !== $participation ? $this->events->find( $participation->eventId ) : null;

		return null !== $event && $this->guard->canManageEvent( $userId, $event );
	}

	/**
	 * Разбор экзаменной попытки для экрана проверки (8.4.2): режим `manage` плюс итог, имя участника и состояние результата.
	 *
	 * @return array<string, mixed>|null null — попытка не найдена или не экзаменная.
	 *
	 * @throws CodedException `ExamAccess` — нет права на проведение попытки.
	 */
	public function reviewFor( int $actorUserId, AttemptDTO $attempt ): ?array {
		if ( ! $this->canManageAttempt( $actorUserId, $attempt ) ) {
			throw new CodedException( ErrorCode::ExamAccess, 'Работа не найдена.' );
		}

		$participation = $this->participations->find( (int) $attempt->examParticipationId );
		$participant   = null !== $participation ? $this->participants->find( $participation->participantId ) : null;
		$event         = null !== $participation ? $this->events->find( $participation->eventId ) : null;
		$view          = $this->reviews->forViewer( $attempt->id, ExamReviewProjection::MODE_MANAGE );
		if ( null === $participation || null === $participant || null === $event || null === $view ) {
			return null;
		}

		$audience = ExamAudience::from( $participation->audience );
		$status   = $this->resultStatus( $attempt, $audience );

		unset( $view['group_id'] );

		return $view + array(
			'exam'                => true,
			'audience'            => $audience->value,
			'participant_name'    => $this->participantName( $participant ),
			'result'              => $this->scores->summarize( $attempt, $event ),
			'result_status'       => $status,
			'result_status_label' => $this->resultLabel( $status, $audience ),
			'approvable'          => self::RESULT_READY === $status,
			'correctable'         => $attempt->isApproved(),
		);
	}

	/**
	 * Раздел «Результаты» (8.7): работы проведений предмета с фильтрами; только проведения, доступные пользователю.
	 *
	 * @param array{event_id?: int, session_id?: int, status?: string, audience?: string, source_id?: int} $filters
	 *        `status` — `pending_review` | `ready` | `approved` | `all`; `audience` — `student` | `guest` | `all`.
	 *
	 * @return array{filters: array<string, mixed>, items: list<array<string, mixed>>}
	 */
	public function results( int $actorUserId, string $subjectKey, array $filters ): array {
		$events = array_values( array_filter(
			$this->events->findBySubjectKey( $subjectKey ),
			fn ( ExamEventDTO $event ): bool => in_array( $event->status, array( 'published', 'completed' ), true )
				&& $this->guard->canManageEvent( $actorUserId, $event )
		) );

		$eventId  = (int) ( $filters['event_id'] ?? 0 );
		$selected = $eventId > 0
			? array_values( array_filter( $events, static fn ( ExamEventDTO $e ): bool => $e->id === $eventId ) )
			: $events;

		$status   = (string) ( $filters['status'] ?? 'all' );
		$audience = (string) ( $filters['audience'] ?? 'all' );
		$sourceId = (int) ( $filters['source_id'] ?? 0 );
		$session  = (int) ( $filters['session_id'] ?? 0 );

		$items = array();
		foreach ( $selected as $event ) {
			foreach ( $this->resultRows( $event ) as $row ) {
				if ( ( 'all' !== $status && $row['result_status'] !== $status )
					|| ( 'all' !== $audience && $row['audience'] !== $audience )
					|| ( $sourceId > 0 && $row['source_id'] !== $sourceId )
					|| ( $session > 0 && $row['session_id'] !== $session ) ) {
					continue;
				}
				$items[] = $row;
			}
		}

		return array(
			'filters' => array(
				'events'   => array_map( static fn ( ExamEventDTO $e ): array => array( 'id' => $e->id, 'title' => $e->title ), $events ),
				'sessions' => 1 === count( $selected ) ? $this->siblings( $selected[0] ) : array(),
				'sources'  => 1 === count( $selected ) ? $this->sourceOptions( $selected[0]->id ) : array(),
			),
			'items'   => $items,
		);
	}

	/**
	 * Строки результатов одного проведения: по одной на участие с попыткой.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function resultRows( ExamEventDTO $event ): array {
		$participations = array_filter( $this->participations->findByEvent( $event->id ), static fn ( ExamParticipationDTO $p ): bool => $p->hasAttempt() );
		if ( array() === $participations ) {
			return array();
		}

		$attempts = array();
		foreach ( $this->attempts->listByParticipations( array_map( static fn ( ExamParticipationDTO $p ): int => $p->id, $participations ) ) as $attempt ) {
			$attempts[ (int) $attempt->examParticipationId ] = $attempt;
		}

		$rows = array();
		foreach ( $participations as $participation ) {
			$attempt     = $attempts[ $participation->id ] ?? null;
			$participant = $this->participants->find( $participation->participantId );
			if ( null === $attempt || null === $participant ) {
				continue;
			}

			$registration = null !== $attempt->examRegistrationId ? $this->registrations->find( $attempt->examRegistrationId ) : null;
			$session      = null !== $registration ? $this->sessions->find( $registration->sessionId ) : null;
			$audience     = ExamAudience::from( $participation->audience );
			$result       = $this->resultStatus( $attempt, $audience );
			$source       = null !== $participation->sourceId ? $this->sources->find( $participation->sourceId ) : null;
			$start        = null !== $session ? $this->time->toLocal( $session->scheduledAt ) : null;

			$rows[] = array(
				'attempt_id'          => $attempt->id,
				'participation_id'    => $participation->id,
				'result_version'      => $attempt->resultVersion,
				'event_id'            => $event->id,
				'event_title'         => $event->title,
				'session_id'          => $session->id ?? 0,
				'session_date'        => null !== $start ? substr( $start, 0, 10 ) : '',
				'session_time'        => null !== $start ? substr( $start, 11, 5 ) : '',
				'name'                => $this->participantName( $participant ),
				'audience'            => $audience->value,
				'audience_label'      => $audience->label(),
				'source'              => $source->schoolName ?? '',
				'source_id'           => $participation->sourceId ?? 0,
				'result_status'       => $result,
				'result_status_label' => $this->resultLabel( $result, $audience ),
				'score'               => AttemptStatus::InProgress === $attempt->status ? null : $this->scores->summarize( $attempt, $event ),
			);
		}

		return $rows;
	}

	/** @return list<array{id: int, label: string}> */
	private function sourceOptions( int $eventId ): array {
		return array_map(
			static fn ( $source ): array => array( 'id' => $source->id, 'label' => $source->schoolName . ' · ' . $source->grade . ' класс' ),
			$this->sources->listByEvent( $eventId )
		);
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
			'status'    => 'cancelled' === $row['registration_status'] ? 'Запись отменена' : (string) $row['progress_label'],
			'primary'   => null !== $score ? $score['primary'] . ' / ' . $score['primary_max'] : '',
			'secondary' => $secondary,
		);
	}

	/**
	 * Допуск гостя (8.8.2): сотрудник проверил данные и согласие представителя на площадке. Без допуска ссылка входа не выдаётся.
	 * Допуск снимается, пока попытка не начата.
	 *
	 * @throws CodedException
	 */
	public function admit( int $actorUserId, int $participationId, bool $admitted ): void {
		$participation = $this->guestParticipationFor( $actorUserId, $participationId );
		if ( $admitted && null === $this->activeRegistrationOf( $participation ) ) {
			throw new CodedException( ErrorCode::ExamConflict, 'У участника нет действующей записи.' );
		}
		if ( $participation->hasAttempt() ) {
			throw new CodedException( ErrorCode::ExamStarted, 'Попытка уже начата: допуск изменить нельзя.' );
		}

		$this->participations->setAdmission( $participationId, $admitted ? $this->time->nowUtc() : null, $admitted ? $actorUserId : null );
	}

	/**
	 * Ссылка входа гостя (11b.1.2): допуск и действующая запись обязательны, сеанс не закончился. Прежний ключ отзывается, поколение растёт.
	 * Права `ExportPII` и `ManageLmsPlatform` не нужны: ссылка не содержит персональных данных.
	 *
	 * @return string Адрес с ключом — единственный раз.
	 *
	 * @throws CodedException
	 */
	public function issueEntryLink( int $actorUserId, int $participationId ): string {
		$participation = $this->guestParticipationFor( $actorUserId, $participationId );
		$registration  = $this->activeRegistrationOf( $participation );
		if ( null === $registration ) {
			throw new CodedException( ErrorCode::ExamConflict, 'У участника нет действующей записи.' );
		}
		if ( null === $participation->admittedAt ) {
			throw new CodedException( ErrorCode::ExamConflict, 'Сначала отметьте допуск участника.' );
		}
		$session = $this->sessions->find( $registration->sessionId );
		if ( null === $session || $session->plannedEndAt <= $this->time->nowUtc() ) {
			throw new CodedException( ErrorCode::ExamClosed, 'Сеанс уже закончился.' );
		}

		$plain = $this->tokens->issue( ExamTokenPurpose::Entry, $participationId, $actorUserId, $session->plannedEndAt );

		return PageRoutes::ExamEntry->url() . '?k=' . $plain;
	}

	/**
	 * Личная ссылка результата (11b.5.1): право `ShareExamResults` и управление проведением; участие гостевое, работа сдана.
	 * Срок ключа — конец проведения + срок хранения гостевых данных. Открытый ключ не хранится: «скопировать ещё раз» = перевыпуск,
	 * прежняя ссылка перестаёт работать. На идущий экзамен и ссылку входа не влияет (другое назначение).
	 *
	 * @return string Адрес с ключом — единственный раз.
	 *
	 * @throws CodedException
	 */
	public function issueResultLink( int $actorUserId, int $participationId ): string {
		$participation = $this->resultLinkParticipation( $actorUserId, $participationId );
		$attempt       = null !== $participation->currentAttemptId ? $this->attempts->find( $participation->currentAttemptId ) : null;
		if ( null === $attempt || AttemptStatus::InProgress === $attempt->status ) {
			throw new CodedException( ErrorCode::ExamConflict, 'Ссылку результата можно выдать после сдачи работы.' );
		}

		$event   = $this->events->find( $participation->eventId );
		$expires = $this->time->addMinutes( $this->time->endOfLocalDayUtc( (string) $event?->periodTo ), $this->config->examGuestRetentionDays() * 1440 );
		$plain   = $this->tokens->issue( ExamTokenPurpose::Result, $participationId, $actorUserId, $expires );

		$this->logEvents->dispatch( LogEvent::ExamGuestLinkIssued, new EntityChangedEvent( $actorUserId, OperationType::Update, EntityType::ExamParticipation, $participationId, 'ссылка результата' ) );

		return PageRoutes::ExamResult->url() . '?k=' . $plain;
	}

	/**
	 * Отзыв ссылки результата без нового ключа: после отзыва страница открывается только 404. Сессию входа не затрагивает.
	 *
	 * @throws CodedException
	 */
	public function revokeResultLink( int $actorUserId, int $participationId ): void {
		$this->resultLinkParticipation( $actorUserId, $participationId );
		$this->tokens->revoke( ExamTokenPurpose::Result, $participationId );

		$this->logEvents->dispatch( LogEvent::ExamGuestLinkRevoked, new EntityChangedEvent( $actorUserId, OperationType::Update, EntityType::ExamParticipation, $participationId, 'ссылка результата' ) );
	}

	/**
	 * Участие гостя для действий со ссылкой результата: нужен `ShareExamResults` (не `ManageExamGuests`) и управление проведением.
	 *
	 * @throws CodedException
	 */
	private function resultLinkParticipation( int $actorUserId, int $participationId ): ExamParticipationDTO {
		$participation = $this->participations->find( $participationId );
		$event         = null !== $participation ? $this->events->find( $participation->eventId ) : null;
		if ( null === $participation || null === $event || ! user_can( $actorUserId, Capability::ShareExamResults->value ) || ! $this->guard->canManageEvent( $actorUserId, $event ) ) {
			throw new CodedException( ErrorCode::ExamAccess, 'Участие не найдено.' );
		}
		if ( ExamAudience::Guest->value !== $participation->audience ) {
			throw new CodedException( ErrorCode::ExamAccess, 'Это действие доступно только для гостей.' );
		}

		return $participation;
	}

	/**
	 * Ручная отметка «Ссылка передана» (8.8.6): ставит сотрудник после того, как отдал ссылку входа или результата. **Копирование ссылки отметку не ставит.**
	 *
	 * @throws CodedException
	 */
	public function markLinkPassed( int $actorUserId, int $participationId, ExamTokenPurpose $purpose ): void {
		if ( ! in_array( $purpose, array( ExamTokenPurpose::Entry, ExamTokenPurpose::Result ), true ) ) {
			throw new CodedException( ErrorCode::ExamConflict, 'Эту ссылку отметить нельзя.' );
		}
		$this->guestParticipationFor( $actorUserId, $participationId );

		$token = $this->tokens->activeToken( $purpose, $participationId );
		if ( null === $token ) {
			throw new CodedException( ErrorCode::ExamConflict, 'Сначала выдайте ссылку.' );
		}
		$this->tokens->markPassed( $token->id, $actorUserId );
	}

	/**
	 * Участие гостя, которым сотрудник вправе управлять (право на гостей + проведение). Чужое и ученическое — «не найдено».
	 *
	 * @throws CodedException
	 */
	private function guestParticipationFor( int $actorUserId, int $participationId ): ExamParticipationDTO {
		$participation = $this->participations->find( $participationId );
		$event         = null !== $participation ? $this->events->find( $participation->eventId ) : null;
		if ( null === $participation || null === $event || ! $this->guard->canManageEventGuests( $actorUserId, $event ) ) {
			throw new CodedException( ErrorCode::ExamAccess, 'Участие не найдено.' );
		}
		if ( ExamAudience::Guest->value !== $participation->audience ) {
			throw new CodedException( ErrorCode::ExamAccess, 'Это действие доступно только для гостей.' );
		}

		return $participation;
	}

	private function activeRegistrationOf( ExamParticipationDTO $participation ): ?ExamRegistrationDTO {
		$registration = null !== $participation->activeRegistrationId ? $this->registrations->find( $participation->activeRegistrationId ) : null;

		return null !== $registration && 1 === $registration->activeSlot && ExamRegistrationStatus::Confirmed->value === $registration->status ? $registration : null;
	}

	/**
	 * Отметка прихода: атрибут операционный, на допуск и неявку не влияет. Повторная отметка перезаписывает автора и время.
	 *
	 * @throws CodedException
	 */
	public function markArrival( int $actorUserId, int $registrationId, bool $arrived ): void {
		$registration  = $this->registrations->find( $registrationId );
		$participation = null !== $registration ? $this->participations->find( $registration->participationId ) : null;
		$event         = null !== $participation ? $this->events->find( $participation->eventId ) : null;
		if ( null === $registration || null === $event || ! $this->guard->canManageEvent( $actorUserId, $event ) ) {
			throw new CodedException( ErrorCode::ExamAccess, 'Запись не найдена.' );
		}
		if ( 1 !== $registration->activeSlot ) {
			throw new CodedException( ErrorCode::ExamConflict, 'Запись не действует.' );
		}

		$this->registrations->setArrival(
			$registrationId,
			$arrived ? $this->time->nowUtc() : null,
			$arrived ? $actorUserId : null
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
			$actions = AttemptStatus::InProgress === $attempt->status ? array( self::ACTION_EXTEND ) : array( self::ACTION_OPEN_WORK );
		} elseif ( $active ) {
			$actions = array( self::ACTION_CANCEL );
			if ( array() !== $otherOpenSessions ) {
				$actions[] = self::ACTION_TRANSFER;
			}
			$actions[] = self::ACTION_ARRIVAL;
		}

		// Гость: допуск после личной проверки на площадке; ссылка входа — только допущенному (и пока не сдал).
		if ( null !== $participation && ExamAudience::Guest->value === $participation->audience && ( $active || ( null !== $attempt && AttemptStatus::InProgress === $attempt->status ) ) ) {
			if ( null === $attempt ) {
				$actions[] = self::ACTION_ADMIT;
			}
			if ( null !== $participation->admittedAt ) {
				$actions[] = self::ACTION_ENTRY_LINK;
				if ( $this->tokens->hasActive( ExamTokenPurpose::Entry, $participation->id ) ) {
					$actions[] = self::ACTION_MARK_ENTRY_PASSED;
				}
			}
		}

		// Удаление данных гостя по его запросу: ручное действие, доступно строке любого гостя, пока данные не обезличены.
		if ( null !== $participation && ExamAudience::Guest->value === $participation->audience ) {
			$actions[] = self::ACTION_ANONYMIZE;
		}

		// Ссылка результата — гостю со сданной работой (выдача и перевыпуск); отзыв — когда ссылка уже выдана (флаг в строке).
		if ( null !== $participation && ExamAudience::Guest->value === $participation->audience && null !== $attempt && AttemptStatus::InProgress !== $attempt->status ) {
			$actions[] = self::ACTION_RESULT_LINK;
			if ( $this->tokens->hasActive( ExamTokenPurpose::Result, $participation->id ) ) {
				$actions[] = self::ACTION_MARK_RESULT_PASSED;
				$actions[] = self::ACTION_REVOKE_RESULT_LINK;
			}
		}

		return $actions;
	}

	/**
	 * Состояние результата строки.
	 *
	 * Гостю утверждение не требуется: готовая работа для него равнозначна утверждённой («Результат выдан»).
	 */
	public function resultStatus( ?AttemptDTO $attempt, ExamAudience $audience ): string {
		if ( null === $attempt || AttemptStatus::InProgress === $attempt->status ) {
			return self::RESULT_NONE;
		}
		if ( $this->answers->hasPendingAnswers( $attempt->id ) ) {
			return self::RESULT_PENDING_REVIEW;
		}
		if ( $attempt->isApproved() || ExamAudience::Guest === $audience ) {
			return self::RESULT_APPROVED;
		}

		return self::RESULT_READY;
	}

	/** «Передана 10.10 15:00, Иванов» — или null, пока сотрудник не отметил вручную. */
	private function passedLabel( ?\Inc\DTO\Exam\ExamAccessTokenDTO $token ): ?string {
		if ( null === $token || null === $token->passedAt ) {
			return null;
		}
		$local = $this->time->toLocal( $token->passedAt );
		$user  = null !== $token->passedByUserId ? get_userdata( $token->passedByUserId ) : false;

		return sprintf( 'Передана %s.%s %s%s', substr( $local, 8, 2 ), substr( $local, 5, 2 ), substr( $local, 11, 5 ), $user ? ', ' . $user->display_name : '' );
	}

	/** ФИО участника для таблицы сотрудника. */
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

	private function resultLabel( string $result, ExamAudience $audience ): string {
		if ( self::RESULT_APPROVED === $result && ExamAudience::Guest === $audience ) {
			return 'Результат выдан';
		}

		return self::RESULT_LABELS[ $result ];
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
			if ( self::RESULT_PENDING_REVIEW === $row['result_status'] ) {
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
	 * Сеансы проведения для переключателя.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function siblings( ExamEventDTO $event ): array {
		$list = array();
		foreach ( $this->sessions->findByEvent( $event->id ) as $session ) {
			if ( ExamSessionStatus::Cancelled->value === $session->status ) {
				continue;
			}
			$start  = $this->time->toLocal( $session->scheduledAt );
			$list[] = array(
				'id'         => $session->id,
				'date'       => substr( $start, 0, 10 ),
				'time_start' => substr( $start, 11, 5 ),
				'free'       => $session->freeSeats(),
				'status'     => $session->status,
			);
		}

		return $list;
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
}
