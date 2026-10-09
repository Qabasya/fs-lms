<?php

declare( strict_types=1 );

namespace Inc\Cli;

use Inc\Contracts\ServiceInterface;
use Inc\DTO\Assessment\AttemptInputDTO;
use Inc\DTO\Exam\AttemptContext;
use Inc\Enums\Assessment\AttemptStatus;
use Inc\Enums\Exam\ExamAudience;
use Inc\Enums\Exam\ExamRegistrationStatus;
use Inc\Enums\Log\ErrorCode;
use Inc\Repositories\WPDBRepositories\AssessmentAnswerRepository;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Repositories\WPDBRepositories\DuplicateKeyException;
use Inc\Repositories\WPDBRepositories\ExamEventRepository;
use Inc\Repositories\WPDBRepositories\ExamGuestApplicationRepository;
use Inc\Repositories\WPDBRepositories\ExamParticipantRepository;
use Inc\Repositories\WPDBRepositories\ExamParticipationRepository;
use Inc\Repositories\WPDBRepositories\ExamRegistrationRepository;
use Inc\Repositories\WPDBRepositories\ExamSessionRepository;
use Inc\Repositories\WPDBRepositories\ExamSourceRepository;
use Inc\Repositories\WPDBRepositories\RoomRepository;
use Inc\Services\Exam\ExamApprovalService;
use Inc\Services\Exam\ExamAttemptService;
use Inc\Services\Exam\ExamEventService;
use Inc\Services\Exam\ExamHoldService;
use Inc\Services\Exam\ExamRegistrationService;
use Inc\Services\Exam\ExamTime;
use Inc\Shared\CodedException;
use Inc\Shared\Traits\TransactionRunner;
use WP_CLI;

/**
 * Диагностика экзаменов на настоящей базе: самопроверка схемы и стенд параллельных запросов (3.5, 4.4).
 *
 * Гонку доказывает только настоящая MariaDB с несколькими соединениями — тестовые дубли `wpdb` этого не проверяют.
 * Команды стенда пишут данные в рабочие таблицы, поэтому проведения стенда называются `STAND …`, а `stand-clean` удаляет
 * всё, что к ним относится. **Вместимость сеанса стенда задаётся напрямую (`capacity = --seats`), минуя `ExamEventService`:**
 * это тестовая фикстура, а не путь, по которому сеансы создаются в работе. Участники стенда — строки `exam_participants`
 * без `person_id`: `persons`, `student_records` и документы стенд не трогает; запись идёт через `registerParticipant()`.
 *
 * Класс не содержит бизнес-логики и в обычной работе сайта не участвует: регистрируется только при `WP_CLI`.
 *
 * @package Inc\Cli
 */
class ExamStandCommand implements ServiceInterface {

	use TransactionRunner;

	private const TITLE_PREFIX = 'STAND';

	public function __construct(
		private readonly ExamEventRepository $events,
		private readonly ExamSessionRepository $sessions,
		private readonly ExamParticipantRepository $participants,
		private readonly ExamParticipationRepository $participations,
		private readonly ExamRegistrationRepository $registrations,
		private readonly ExamGuestApplicationRepository $applications,
		private readonly ExamSourceRepository $sources,
		private readonly RoomRepository $rooms,
		private readonly ExamRegistrationService $registrationService,
		private readonly ExamHoldService $holdService,
		private readonly ExamEventService $eventService,
		private readonly ExamAttemptService $attemptService,
		private readonly ExamTime $time,
		private readonly AssessmentAttemptRepository $attempts,
		private readonly AssessmentAnswerRepository $answers,
		private readonly ExamApprovalService $approvals,
	) {}

	public function register(): void {
		if ( ! defined( 'WP_CLI' ) ) {
			return;
		}

		WP_CLI::add_command( 'fs-lms exam selftest', array( $this, 'selftest' ) );
		WP_CLI::add_command( 'fs-lms exam stand-seed', array( $this, 'standSeed' ) );
		WP_CLI::add_command( 'fs-lms exam stand-register', array( $this, 'standRegister' ) );
		WP_CLI::add_command( 'fs-lms exam stand-hold', array( $this, 'standHold' ) );
		WP_CLI::add_command( 'fs-lms exam stand-session', array( $this, 'standSession' ) );
		WP_CLI::add_command( 'fs-lms exam stand-report', array( $this, 'standReport' ) );
		WP_CLI::add_command( 'fs-lms exam stand-window', array( $this, 'standWindow' ) );
		WP_CLI::add_command( 'fs-lms exam stand-start', array( $this, 'standStart' ) );
		WP_CLI::add_command( 'fs-lms exam stand-attempts', array( $this, 'standAttempts' ) );
		WP_CLI::add_command( 'fs-lms exam stand-approve', array( $this, 'standApprove' ) );
		WP_CLI::add_command( 'fs-lms exam stand-approve-all', array( $this, 'standApproveAll' ) );
		WP_CLI::add_command( 'fs-lms exam stand-correct', array( $this, 'standCorrect' ) );
		WP_CLI::add_command( 'fs-lms exam stand-clean', array( $this, 'standClean' ) );
	}

	/**
	 * Самопроверка схемы на настоящей базе: условные UPDATE вместимости, уникальные индексы, версии. Всегда завершается ROLLBACK.
	 *
	 * ## EXAMPLES
	 *
	 *     wp fs-lms exam selftest
	 *
	 * @param array $args       Позиционные аргументы
	 * @param array $assoc_args Именованные аргументы
	 */
	public function selftest( array $args, array $assoc_args ): void {
		$results = array();

		try {
			$this->inTransaction( function () use ( &$results ): void {
				$results = $this->runSelfTests();
				throw new SelfTestRollback();
			} );
		} catch ( SelfTestRollback ) {
			// Ожидаемо: проверки закончены, транзакция откатана.
		}

		$rows   = array();
		$failed = false;
		foreach ( $results as $label => $ok ) {
			$rows[] = array( 'проверка' => $label, 'результат' => $ok ? 'OK' : 'FAIL' );
			$failed = $failed || ! $ok;
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'проверка', 'результат' ) );

		if ( $failed ) {
			WP_CLI::halt( 1 );
		}
		WP_CLI::success( 'Все проверки пройдены, данные откатаны.' );
	}

	/**
	 * Создаёт проведение стенда: опубликованное, с открытой записью, сеансами в будущем и участниками без учётной записи.
	 *
	 * ## OPTIONS
	 *
	 * [--seats=<n>]
	 * : Вместимость каждого сеанса. Список через запятую (5,10,15) создаёт по сеансу на значение, `--sessions` тогда игнорируется.
	 * ---
	 * default: 20
	 * ---
	 *
	 * [--participants=<m>]
	 * : Сколько участников создать.
	 * ---
	 * default: 100
	 * ---
	 *
	 * [--sessions=<k>]
	 * : Сколько сеансов создать (0 — без сеансов, для сценария занятости кабинета).
	 * ---
	 * default: 1
	 * ---
	 *
	 * [--subject=<key>]
	 * : Ключ предмета проведения.
	 * ---
	 * default: stand
	 * ---
	 *
	 * [--owner=<user_id>]
	 * : Владелец проведения.
	 * ---
	 * default: 1
	 * ---
	 *
	 * @param array $args       Позиционные аргументы
	 * @param array $assoc_args Именованные аргументы
	 */
	public function standSeed( array $args, array $assoc_args ): void {
		$seatList = array_values( array_filter( array_map( 'intval', explode( ',', (string) ( $assoc_args['seats'] ?? '20' ) ) ), static fn ( int $n ): bool => $n > 0 ) );
		$seatList = array() === $seatList ? array( 20 ) : $seatList;
		$participants = max( 0, (int) ( $assoc_args['participants'] ?? 100 ) );
		$sessions     = count( $seatList ) > 1 ? count( $seatList ) : max( 0, (int) ( $assoc_args['sessions'] ?? 1 ) );
		$subject      = (string) ( $assoc_args['subject'] ?? 'stand' );
		$owner        = max( 1, (int) ( $assoc_args['owner'] ?? 1 ) );

		$roomId = 0;
		foreach ( $this->rooms->findAll( true ) as $room ) {
			if ( $room->hasCapacity() ) {
				$roomId = $room->id;
				break;
			}
		}
		if ( $sessions > 0 && 0 === $roomId ) {
			WP_CLI::error( 'Нет кабинета с вместимостью: укажите места в «Настройки → Кабинеты» (этап 0.9).' );
		}

		$now     = $this->time->nowUtc();
		$eventId = $this->createStandEvent( $subject, $owner );

		$sessionIds = array();
		for ( $i = 0; $i < $sessions; $i++ ) {
			$start        = $this->time->addMinutes( $now, 2 * 1440 + $i * 300 );
			$sessionIds[] = $this->sessions->insert( array(
				'event_id'            => $eventId,
				'assessment_id'       => 1,
				'scheduled_at'        => $start,
				'planned_end_at'      => $this->time->addMinutes( $start, 235 ),
				'room_id'             => $roomId,
				'capacity'            => $seatList[ $i ] ?? $seatList[0],
				'occupied_count'      => 0,
				'responsible_user_id' => $owner,
				'status'              => 'open',
				'version'             => 1,
				'created_at'          => $now,
				'updated_at'          => $now,
			) );
		}

		$first = 0;
		$last  = 0;
		for ( $i = 1; $i <= $participants; $i++ ) {
			$last = $this->participants->insert( array( 'school_name' => self::TITLE_PREFIX, 'created_at' => $now, 'updated_at' => $now ) );
			$first = 0 === $first ? $last : $first;
		}

		$sourceId = $this->sources->insert( array(
			'event_id'               => $eventId,
			'school_name'            => self::TITLE_PREFIX,
			'school_name_normalized' => 'stand',
			'grade'                  => 9,
			'teacher_name'           => self::TITLE_PREFIX,
			'label'                  => self::TITLE_PREFIX,
			'created_by_user_id'     => $owner,
			'version'                => 1,
			'created_at'             => $now,
			'updated_at'             => $now,
		) );

		WP_CLI::line( 'event=' . $eventId );
		WP_CLI::line( 'sessions=' . implode( ',', $sessionIds ) );
		WP_CLI::line( 'participants=' . $first . '-' . $last );
		WP_CLI::line( 'source=' . $sourceId );
	}

	/**
	 * Одна попытка записи участника стенда на сеанс. Печатает одно слово: confirmed, full, held, conflict, replay или error:<текст>.
	 *
	 * ## OPTIONS
	 *
	 * --session=<id>
	 * : ID сеанса.
	 *
	 * --participant=<id>
	 * : ID участника.
	 *
	 * [--key=<string>]
	 * : Ключ идемпотентности (по умолчанию k<участник>).
	 *
	 * @param array $args       Позиционные аргументы
	 * @param array $assoc_args Именованные аргументы
	 */
	public function standRegister( array $args, array $assoc_args ): void {
		$participantId = (int) ( $assoc_args['participant'] ?? 0 );
		$key           = (string) ( $assoc_args['key'] ?? 'k' . $participantId );

		try {
			$this->registrationService->registerParticipant( $participantId, ExamAudience::Student, (int) ( $assoc_args['session'] ?? 0 ), $key, null );
			WP_CLI::line( 'confirmed' );
		} catch ( CodedException $e ) {
			WP_CLI::line( $this->wordFor( $e ) );
		} catch ( \Throwable $e ) {
			WP_CLI::line( 'error:' . $this->oneLine( $e->getMessage() ) );
		}
	}

	/**
	 * Одна бронь гостя стенда на сеанс (личность stand-<номер>). Печатает: held, full, conflict, replay или error:<текст>.
	 *
	 * ## OPTIONS
	 *
	 * --session=<id>
	 * : ID сеанса.
	 *
	 * --n=<number>
	 * : Номер гостя (личность и ключ строятся из него).
	 *
	 * [--expired]
	 * : Поставить срок брони в прошлое (сценарий истёкшей брони).
	 *
	 * @param array $args       Позиционные аргументы
	 * @param array $assoc_args Именованные аргументы
	 */
	public function standHold( array $args, array $assoc_args ): void {
		$sessionId = (int) ( $assoc_args['session'] ?? 0 );
		$number    = (string) ( $assoc_args['n'] ?? '0' );

		try {
			$session = $this->sessions->find( $sessionId );
			$sources = null !== $session ? $this->sources->listByEvent( $session->eventId ) : array();
			if ( null === $session || array() === $sources ) {
				WP_CLI::line( 'error:нет сеанса или источника стенда' );
				return;
			}

			$application = $this->holdService->capture( array(
				'event_id'      => $session->eventId,
				'session_id'    => $sessionId,
				'source_id'     => $sources[0]->id,
				'identity_hash' => hash( 'sha256', 'stand-' . $number ),
				'request_key'   => 'h' . $number,
			), 20 );

			if ( isset( $assoc_args['expired'] ) ) {
				$this->applications->update( $application->id, array( 'hold_expires_at' => $this->time->addMinutes( $this->time->nowUtc(), -5 ) ), $application->version );
			}
			WP_CLI::line( 'held' );
		} catch ( CodedException $e ) {
			WP_CLI::line( $this->wordFor( $e ) );
		} catch ( \Throwable $e ) {
			WP_CLI::line( 'error:' . $this->oneLine( $e->getMessage() ) );
		}
	}

	/**
	 * Назначает сеанс в кабинет на время — через ExamEventService::saveSession(), то есть с блокировкой кабинета.
	 * Печатает: created, conflict или error:<текст>. Для сценария room-race.
	 *
	 * ## OPTIONS
	 *
	 * [--event=<id>]
	 * : ID проведения стенда. Без него процесс создаёт **своё** проведение: тогда сеансы разных проведений борются за один кабинет,
	 * и сериализует их только блокировка кабинета (в одном проведении их сериализует блокировка самого проведения).
	 *
	 * [--subject=<key>]
	 * : Предмет своего проведения (тот же, что у варианта).
	 * ---
	 * default: inf_ege
	 * ---
	 *
	 * --room=<id>
	 * : ID кабинета.
	 *
	 * --date=<date>
	 * : Местная дата сеанса в формате Y-m-d (внутри периода проведения).
	 *
	 * --time=<time>
	 * : Местное время начала в формате H:i.
	 *
	 * --assessment=<id>
	 * : ID опубликованного варианта экзамена того же предмета.
	 *
	 * [--actor=<user_id>]
	 * : Пользователь, от имени которого создаётся сеанс (по умолчанию — владелец проведения).
	 *
	 * @param array $args       Позиционные аргументы
	 * @param array $assoc_args Именованные аргументы
	 */
	public function standSession( array $args, array $assoc_args ): void {
		$actor   = max( 1, (int) ( $assoc_args['actor'] ?? 1 ) );
		$eventId = (int) ( $assoc_args['event'] ?? 0 );
		if ( 0 === $eventId ) {
			$eventId = $this->createStandEvent( (string) ( $assoc_args['subject'] ?? 'inf_ege' ), $actor );
		}
		$event = $this->events->find( $eventId );
		if ( null === $event ) {
			WP_CLI::line( 'error:проведение не найдено' );
			return;
		}

		try {
			$this->eventService->saveSession(
				(int) ( $assoc_args['actor'] ?? $event->ownerUserId ),
				$eventId,
				array(
					'date'          => (string) ( $assoc_args['date'] ?? '' ),
					'time'          => (string) ( $assoc_args['time'] ?? '' ),
					'assessment_id' => (int) ( $assoc_args['assessment'] ?? 0 ),
					'room_id'       => (int) ( $assoc_args['room'] ?? 0 ),
				),
				null,
				null
			);
			WP_CLI::line( 'created' );
		} catch ( CodedException $e ) {
			WP_CLI::line( ErrorCode::ExamConflict === $e->errorCode ? 'conflict' : 'error:' . $this->oneLine( $e->getMessage() ) );
		} catch ( \Throwable $e ) {
			WP_CLI::line( 'error:' . $this->oneLine( $e->getMessage() ) );
		}
	}

	/**
	 * Числа сеанса для сверки с ожиданием: вместимость, занятые места, действующие записи и брони, участия с двумя записями.
	 *
	 * ## OPTIONS
	 *
	 * --session=<id>
	 * : ID сеанса.
	 *
	 * @param array $args       Позиционные аргументы
	 * @param array $assoc_args Именованные аргументы
	 */
	public function standReport( array $args, array $assoc_args ): void {
		$session = $this->sessions->find( (int) ( $assoc_args['session'] ?? 0 ) );
		if ( null === $session ) {
			WP_CLI::error( 'Сеанс не найден.' );
		}

		WP_CLI::line( sprintf(
			'capacity=%d occupied=%d active=%d holds=%d doubles=%d',
			$session->capacity,
			$session->occupiedCount,
			$this->registrations->countActiveBySession( $session->id ),
			$this->applications->countHeldBySession( $session->id ),
			$this->registrations->countParticipationsWithMultipleActive( $session->eventId )
		) );
	}

	/**
	 * Переносит сеанс стенда в «идёт сейчас»: плановый конец наступает в заданную секунду, начало — в прошлом.
	 * Нужен сценарию start-vs-missed: записи создаются заранее, а граница «старт или неявка» — в момент запуска.
	 *
	 * ## OPTIONS
	 *
	 * --session=<id>
	 * : ID сеанса.
	 *
	 * --end-at=<unix>
	 * : Момент планового конца (секунды Unix).
	 *
	 * [--assessment=<id>]
	 * : Вариант сеанса (по умолчанию тот, что уже стоит); в снимок проведения кладётся длительность 235 минут.
	 *
	 * @param array $args       Позиционные аргументы
	 * @param array $assoc_args Именованные аргументы
	 */
	public function standWindow( array $args, array $assoc_args ): void {
		$session = $this->sessions->find( (int) ( $assoc_args['session'] ?? 0 ) );
		if ( null === $session ) {
			WP_CLI::error( 'Сеанс не найден.' );
		}

		$endAt        = (int) ( $assoc_args['end-at'] ?? 0 );
		$assessmentId = (int) ( $assoc_args['assessment'] ?? $session->assessmentId );
		$event = $this->events->find( $session->eventId );
		if ( null === $event ) {
			WP_CLI::error( 'Проведение не найдено.' );
		}
		$this->sessions->update( $session->id, array(
			'assessment_id'  => $assessmentId,
			'scheduled_at'   => gmdate( 'Y-m-d H:i:s', $endAt - 235 * 60 ),
			'planned_end_at' => gmdate( 'Y-m-d H:i:s', $endAt ),
		), $session->version );
		$this->events->update( $event->id, array( 'variant_snapshot' => (string) wp_json_encode( array( (string) $assessmentId => array( 'duration_minutes' => 235 ) ) ) ), $event->version );

		WP_CLI::line( 'window end=' . gmdate( 'Y-m-d H:i:s', $endAt ) );
	}

	/**
	 * Старт попытки участником стенда. Печатает одно слово: started, missed (время начала истекло), early, denied или error:<текст>.
	 * Участнику без учётной записи подставляется условный person_id (900000 + номер участника).
	 *
	 * ## OPTIONS
	 *
	 * --event=<id>
	 * : ID проведения стенда.
	 *
	 * --participant=<id>
	 * : ID участника.
	 *
	 * [--at=<unix>]
	 * : Подождать до этого момента (секунды Unix, допускается дробная часть) и только потом стартовать:
	 *   все процессы загружают WordPress заранее и бьют в границу одновременно.
	 *
	 * [--jitter=<ms>]
	 * : Случайный сдвиг момента старта в пределах ±ms миллисекунд.
	 * ---
	 * default: 0
	 * ---
	 *
	 * @param array $args       Позиционные аргументы
	 * @param array $assoc_args Именованные аргументы
	 */
	public function standStart( array $args, array $assoc_args ): void {
		$participantId = (int) ( $assoc_args['participant'] ?? 0 );
		$participation = $this->participations->findByEventAndParticipant( (int) ( $assoc_args['event'] ?? 0 ), $participantId );
		$history       = null !== $participation ? $this->registrations->findByParticipation( $participation->id ) : array();
		if ( null === $participation || array() === $history ) {
			WP_CLI::line( 'error:записи участника нет' );
			return;
		}

		$at = (float) ( $assoc_args['at'] ?? 0 );
		if ( $at > 0 ) {
			$jitter = max( 0, (int) ( $assoc_args['jitter'] ?? 0 ) );
			$wait   = $at + ( $jitter > 0 ? random_int( -$jitter, $jitter ) / 1000 : 0 ) - microtime( true );
			if ( $wait > 0 ) {
				usleep( (int) ( $wait * 1_000_000 ) );
			}
		}

		$registration = $history[ array_key_last( $history ) ];
		$ctx          = new AttemptContext( ExamAudience::Student, $participation->id, $registration->id, 900000 + $participantId, 0 );

		try {
			$this->attemptService->start( $ctx );
			WP_CLI::line( 'started' );
		} catch ( CodedException $e ) {
			WP_CLI::line( match ( true ) {
				ErrorCode::ExamNotOpen === $e->errorCode && str_contains( $e->getMessage(), 'истекло' ) => 'missed',
				ErrorCode::ExamNotOpen === $e->errorCode => 'early',
				ErrorCode::ExamAccess === $e->errorCode  => 'denied',
				default                                  => 'error:' . $e->errorCode->value . ' ' . $this->oneLine( $e->getMessage() ),
			} );
		} catch ( \Throwable $e ) {
			WP_CLI::line( 'error:' . $this->oneLine( $e->getMessage() ) );
		}
	}

	/**
	 * Сданные попытки для записанных участников сеанса стенда (для проверки утверждения): статус «оценена», один ответ с баллом 1 из 2.
	 * Попытки пишутся напрямую в таблицы — это фикстура, а не путь, по которому попытки создаются в работе.
	 *
	 * ## OPTIONS
	 *
	 * --session=<id>
	 * : ID сеанса стенда.
	 *
	 * [--n=<count>]
	 * : Сколько попыток создать.
	 * ---
	 * default: 50
	 * ---
	 *
	 * @param array $args       Позиционные аргументы
	 * @param array $assoc_args Именованные аргументы
	 */
	public function standAttempts( array $args, array $assoc_args ): void {
		$session = $this->sessions->find( (int) ( $assoc_args['session'] ?? 0 ) );
		if ( null === $session ) {
			WP_CLI::error( 'Сеанс не найден.' );
		}

		$limit   = max( 1, (int) ( $assoc_args['n'] ?? 50 ) );
		$created = array();
		foreach ( $this->registrations->listBySession( $session->id, array( ExamRegistrationStatus::Confirmed ) ) as $registration ) {
			if ( count( $created ) >= $limit ) {
				break;
			}
			$participation = $this->participations->find( $registration->participationId );
			if ( null === $participation || $participation->hasAttempt() ) {
				continue;
			}

			$attemptId = $this->attempts->create( new AttemptInputDTO(
				assessmentId       : $session->assessmentId,
				studentPersonId    : 900000 + $participation->participantId,
				groupId            : null,
				attemptNumber      : 1,
				startedAt          : $this->time->toLocal( $session->scheduledAt ),
				deadlineAt         : $this->time->toLocal( $session->plannedEndAt ),
				status             : AttemptStatus::Graded,
				examParticipationId: $participation->id,
				examRegistrationId : $registration->id,
			) );
			$this->attempts->update( $attemptId, array( 'total_score' => 1, 'max_score' => 2, 'submitted_at' => $this->time->toLocal( $session->plannedEndAt ) ) );
			$this->answers->upsert( $attemptId, 1, array( 'answer_text' => 'стенд', 'is_correct' => 0, 'score' => 1, 'max_score' => 2 ) );
			$this->participations->setCurrentAttempt( $participation->id, $attemptId );
			$created[] = $attemptId;
		}

		WP_CLI::line( 'attempts=' . ( array() === $created ? '-' : implode( ',', $created ) ) );
	}

	/**
	 * Одно утверждение попытки стенда (для гонки параллельных процессов). Печатает одно слово: approved или skipped:<причина>.
	 *
	 * ## OPTIONS
	 *
	 * --attempt=<id>
	 * : ID попытки.
	 *
	 * [--version=<n>]
	 * : Ожидаемая версия результата (по умолчанию текущая).
	 *
	 * [--actor=<user_id>]
	 * : Кто утверждает.
	 * ---
	 * default: 1
	 * ---
	 *
	 * [--at=<unix>]
	 * : Подождать до этого момента (секунды Unix) и только потом утверждать.
	 *
	 * @param array $args       Позиционные аргументы
	 * @param array $assoc_args Именованные аргументы
	 */
	public function standApprove( array $args, array $assoc_args ): void {
		$attemptId = (int) ( $assoc_args['attempt'] ?? 0 );
		$version   = isset( $assoc_args['version'] ) ? (int) $assoc_args['version'] : (int) ( $this->attempts->find( $attemptId )?->resultVersion ?? 0 );
		$this->waitUntil( (float) ( $assoc_args['at'] ?? 0 ) );

		try {
			$result = $this->approvals->approve( max( 1, (int) ( $assoc_args['actor'] ?? 1 ) ), $attemptId, $version );
			WP_CLI::line( 'approved' === $result['status'] ? 'approved' : 'skipped:' . ( $result['reason'] ?? '' ) );
		} catch ( \Throwable $e ) {
			WP_CLI::line( 'error:' . $this->oneLine( $e->getMessage() ) );
		}
	}

	/**
	 * Массовое утверждение всех попыток сеанса стенда одним вызовом; печатает число утверждённых, пропущенных и время в миллисекундах.
	 *
	 * ## OPTIONS
	 *
	 * --session=<id>
	 * : ID сеанса стенда.
	 *
	 * @param array $args       Позиционные аргументы
	 * @param array $assoc_args Именованные аргументы
	 */
	public function standApproveAll( array $args, array $assoc_args ): void {
		$items = array();
		foreach ( $this->registrations->listBySession( (int) ( $assoc_args['session'] ?? 0 ) ) as $registration ) {
			$participation = $this->participations->find( $registration->participationId );
			$attempt       = null !== $participation && null !== $participation->currentAttemptId ? $this->attempts->find( $participation->currentAttemptId ) : null;
			if ( null !== $attempt ) {
				$items[] = array( 'attempt_id' => $attempt->id, 'result_version' => $attempt->resultVersion );
			}
		}

		$started = microtime( true );
		$result  = $this->approvals->approveMany( 1, $items );

		WP_CLI::line( sprintf( 'approved=%d skipped=%d ms=%d', $result['approved'], count( $result['skipped'] ), (int) round( ( microtime( true ) - $started ) * 1000 ) ) );
	}

	/**
	 * Одно исправление результата утверждённой попытки стенда (для гонки двух проверяющих). Печатает: corrected или error:<код>.
	 *
	 * ## OPTIONS
	 *
	 * --attempt=<id>
	 * : ID попытки.
	 *
	 * --version=<n>
	 * : Версия результата, которую «видел» проверяющий.
	 *
	 * [--score=<n>]
	 * : Новый балл за задание 1.
	 * ---
	 * default: 2
	 * ---
	 *
	 * [--at=<unix>]
	 * : Подождать до этого момента (секунды Unix).
	 *
	 * @param array $args       Позиционные аргументы
	 * @param array $assoc_args Именованные аргументы
	 */
	public function standCorrect( array $args, array $assoc_args ): void {
		$this->waitUntil( (float) ( $assoc_args['at'] ?? 0 ) );

		try {
			$this->approvals->correct(
				1,
				(int) ( $assoc_args['attempt'] ?? 0 ),
				array( array( 'task_id' => 1, 'score' => (float) ( $assoc_args['score'] ?? 2 ) ) ),
				'Стенд: исправление',
				(int) ( $assoc_args['version'] ?? 0 )
			);
			WP_CLI::line( 'corrected' );
		} catch ( CodedException $e ) {
			WP_CLI::line( 'error:' . $e->errorCode->value );
		} catch ( \Throwable $e ) {
			WP_CLI::line( 'error:' . $this->oneLine( $e->getMessage() ) );
		}
	}

	private function waitUntil( float $at ): void {
		$wait = $at - microtime( true );
		if ( $at > 0 && $wait > 0 ) {
			usleep( (int) ( $wait * 1_000_000 ) );
		}
	}

	/**
	 * Удаляет все данные проведений стенда (название STAND…) из таблиц exam_*.
	 *
	 * @param array $args       Позиционные аргументы
	 * @param array $assoc_args Именованные аргументы
	 */
	public function standClean( array $args, array $assoc_args ): void {
		WP_CLI::success( sprintf( 'Удалено проведений стенда: %d.', $this->events->purgeByTitlePrefix( self::TITLE_PREFIX ) ) );
	}

	// ---------------------------------------------------------------------------------------------------------------------------------

	/**
	 * Семь проверок схемы на реальной базе (внутри транзакции, которую откатывает вызывающий).
	 *
	 * @return array<string, bool> Название проверки → пройдена.
	 */
	private function runSelfTests(): array {
		$now     = $this->time->nowUtc();
		$results = array();

		$eventId   = $this->events->insert( array(
			'subject_key' => 'selftest', 'title' => 'SELFTEST', 'owner_user_id' => 1, 'status' => 'draft', 'period_from' => gmdate( 'Y-m-d' ),
			'period_to' => gmdate( 'Y-m-d' ), 'version' => 1, 'created_at' => $now, 'updated_at' => $now,
		) );
		$sessionId = $this->sessions->insert( array(
			'event_id' => $eventId, 'assessment_id' => 1, 'scheduled_at' => $now, 'planned_end_at' => $this->time->addMinutes( $now, 60 ), 'room_id' => 1,
			'capacity' => 1, 'occupied_count' => 0, 'responsible_user_id' => 1, 'status' => 'open', 'version' => 1, 'created_at' => $now, 'updated_at' => $now,
		) );
		$results['1. проведение и сеанс с capacity = 1 созданы'] = $eventId > 0 && $sessionId > 0;

		$first  = $this->sessions->occupySeat( $sessionId );
		$second = $this->sessions->occupySeat( $sessionId );
		$results['2. occupySeat: true, затем false; occupied_count = 1'] = $first && ! $second && 1 === ( $this->sessions->find( $sessionId )?->occupiedCount );

		$release1 = $this->sessions->releaseSeat( $sessionId );
		$release2 = $this->sessions->releaseSeat( $sessionId );
		$results['3. releaseSeat: true, затем false'] = $release1 && ! $release2;

		$participantId   = $this->participants->insert( array( 'school_name' => 'SELFTEST', 'created_at' => $now, 'updated_at' => $now ) );
		$participationId = $this->participations->insert( array(
			'event_id' => $eventId, 'participant_id' => $participantId, 'audience' => 'student', 'version' => 1, 'created_at' => $now, 'updated_at' => $now,
		) );
		$row             = array( 'participation_id' => $participationId, 'session_id' => $sessionId, 'status' => 'confirmed', 'active_slot' => 1, 'created_at' => $now );
		$firstId         = $this->registrations->insert( $row );
		$duplicateFailed = false;
		// Ошибка уникального индекса здесь ожидаема — wpdb не должен печатать её как сбой.
		$previous = $GLOBALS['wpdb']->suppress_errors( true );
		try {
			$this->registrations->insert( $row );
		} catch ( DuplicateKeyException ) {
			$duplicateFailed = true;
		} finally {
			$GLOBALS['wpdb']->suppress_errors( $previous );
		}
		$results['4. вторая действующая запись участия отвергнута уникальным индексом'] = $firstId > 0 && $duplicateFailed;

		$this->registrations->deactivate( $firstId, ExamRegistrationStatus::Cancelled, $now );
		$reinserted = false;
		try {
			$reinserted = $this->registrations->insert( $row ) > 0;
		} catch ( DuplicateKeyException ) {
			$reinserted = false;
		}
		$results['5. после deactivate() новая запись вставляется'] = $reinserted;

		$results['6. update() проведения с неверной версией → false'] = false === $this->events->update( $eventId, array( 'title' => 'SELFTEST 2' ), 99 );

		$person                                                   = 2147480000;
		$results['7. getOrCreateForPerson() дважды даёт один ID'] = $this->participants->getOrCreateForPerson( $person ) === $this->participants->getOrCreateForPerson( $person );

		return $results;
	}

	/** Проведение стенда: опубликованное, запись открыта на месяц вперёд. Название начинается с `STAND` — по нему `stand-clean` находит данные. */
	private function createStandEvent( string $subject, int $owner ): int {
		$now = $this->time->nowUtc();

		return $this->events->insert( array(
			'subject_key'                => $subject,
			'title'                      => self::TITLE_PREFIX . ' ' . gmdate( 'H:i:s' ) . ' ' . bin2hex( random_bytes( 3 ) ),
			'owner_user_id'              => $owner,
			'status'                     => 'published',
			'period_from'                => gmdate( 'Y-m-d' ),
			'period_to'                  => gmdate( 'Y-m-d', time() + 30 * 86400 ),
			'registration_opens_at'      => $this->time->addMinutes( $now, -1440 ),
			'registration_closes_at'     => $this->time->addMinutes( $now, 29 * 1440 ),
			'guest_registration_enabled' => 1,
			'version'                    => 1,
			'created_at'                 => $now,
			'updated_at'                 => $now,
		) );
	}

	/** Слово ответа стенда по коду отказа. */
	private function wordFor( CodedException $e ): string {
		return match ( $e->errorCode ) {
			ErrorCode::ExamFull     => 'full',
			ErrorCode::ExamHeld     => 'held',
			ErrorCode::ExamConflict => 'conflict',
			ErrorCode::ExamReplay   => 'replay',
			default                 => 'error:' . $e->errorCode->value . ' ' . $this->oneLine( $e->getMessage() ),
		};
	}

	private function oneLine( string $text ): string {
		return trim( (string) preg_replace( '/\s+/', ' ', $text ) );
	}
}
