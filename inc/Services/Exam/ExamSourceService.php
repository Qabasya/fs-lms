<?php

declare( strict_types=1 );

namespace Inc\Services\Exam;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Exam\ExamEventDTO;
use Inc\DTO\Exam\ExamSourceDTO;
use Inc\DTO\Log\Events\EntityChangedEvent;
use Inc\Enums\Exam\ExamDirection;
use Inc\Enums\Exam\ExamTokenPurpose;
use Inc\Enums\Log\EntityType;
use Inc\Enums\Log\ErrorCode;
use Inc\Enums\Log\LogEvent;
use Inc\Enums\Log\OperationType;
use Inc\Enums\Wp\PageRoutes;
use Inc\Managers\Assessment\AssessmentManager;
use Inc\Repositories\WPDBRepositories\ExamEventRepository;
use Inc\Repositories\WPDBRepositories\ExamGuestApplicationRepository;
use Inc\Repositories\WPDBRepositories\ExamSourceRepository;
use Inc\Shared\CodedException;
use Inc\Shared\Traits\TransactionRunner;

/**
 * Источники приглашений: школа, класс (9 или 11), школьный преподаватель — и пригласительная ссылка к ним (SPEC §6, §8).
 *
 * Гость попадает на форму только по ссылке источника. Ключ ссылки хранится **только** в `exam_access_tokens` (хеш), в источнике —
 * счётчик поколения и отметка отзыва. Открытый ключ существует только в ответе выдачи и перевыпуска и нигде не сохраняется.
 *
 * Каждая операция требует права на проведение (`canManageEvent`) и право `ManageExamGuests`. Выдача, перевыпуск и отзыв идут в транзакции
 * под блокировкой строки источника: два одновременных выпуска не оставят два действующих ключа. Изменения пишутся в журнал сущностей.
 */
class ExamSourceService {

	use TransactionRunner;

	public function __construct(
		private readonly ExamSourceRepository $sources,
		private readonly ExamEventRepository $events,
		private readonly ExamGuestApplicationRepository $applications,
		private readonly ExamAccessGuard $guard,
		private readonly ExamAccessTokenService $tokens,
		private readonly ExamFormatRegistry $formats,
		private readonly AssessmentManager $assessments,
		private readonly LogEventDispatcherInterface $logEvents,
		private readonly ExamTime $time,
		private readonly GuestSessionService $guestSessions,
	) {}

	/**
	 * Источники проведения для управления (включая отключённые). Ключей и хешей в ответе нет: только «ссылка есть» и поколение.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @throws CodedException
	 */
	public function list( int $actorUserId, int $eventId ): array {
		$this->requireEvent( $actorUserId, $eventId );

		return array_map( fn ( ExamSourceDTO $source ): array => $this->payload( $source ), $this->sources->listByEvent( $eventId ) );
	}

	/**
	 * Создаёт или правит источник. Правка не меняет уже оформленные заявки: у них свой снимок источника.
	 *
	 * @param array<string, mixed> $input `school_name`, `teacher_name`, `grade`.
	 *
	 * @throws CodedException
	 */
	public function save( int $actorUserId, int $eventId, array $input, ?int $sourceId, ?int $expectedVersion ): ExamSourceDTO {
		$event = $this->requireEvent( $actorUserId, $eventId );

		$school  = $this->clean( (string) ( $input['school_name'] ?? '' ) );
		$teacher = $this->clean( (string) ( $input['teacher_name'] ?? '' ) );
		$grade   = (int) ( $input['grade'] ?? 0 );

		if ( '' === $school ) {
			throw new CodedException( ErrorCode::ExamConflict, 'Укажите школу.' );
		}
		if ( '' === $teacher ) {
			throw new CodedException( ErrorCode::ExamConflict, 'Укажите ФИО преподавателя.' );
		}
		if ( 9 !== $grade && 11 !== $grade ) {
			throw new CodedException( ErrorCode::ExamConflict, 'Класс — 9 или 11.' );
		}
		$direction = $this->directionOf( $event );
		if ( $direction->grade() !== $grade ) {
			throw new CodedException( ErrorCode::ExamConflict, 'Класс не соответствует направлению проведения.' );
		}

		$fields = array(
			'school_name'            => $school,
			'school_name_normalized' => mb_strtolower( $school ),
			'grade'                  => $grade,
			'teacher_name'           => $teacher,
			'label'                  => $school . ', ' . $grade . ' класс',
		);

		if ( null === $sourceId ) {
			$now = $this->time->nowUtc();
			$id  = $this->sources->insert( array_merge( $fields, array(
				'event_id'           => $eventId,
				'created_by_user_id' => $actorUserId,
				'version'            => 1,
				'created_at'         => $now,
				'updated_at'         => $now,
			) ) );
			$this->audit( $actorUserId, OperationType::Create, LogEvent::ExamSourceCreated, $id, $school );

			return $this->reload( $id );
		}

		if ( null === $expectedVersion ) {
			throw $this->stale();
		}

		return $this->inTransactionWithRetry( function () use ( $actorUserId, $eventId, $sourceId, $fields, $expectedVersion ): ExamSourceDTO {
			$source = $this->lockSource( $sourceId, $eventId );
			if ( $source->version !== $expectedVersion || ! $this->sources->update( $sourceId, $fields, $expectedVersion ) ) {
				throw $this->stale();
			}
			$this->audit( $actorUserId, OperationType::Update, LogEvent::ExamSourceUpdated, $sourceId, $source->schoolName );

			return $this->reload( $sourceId );
		} );
	}

	/**
	 * Выдаёт первую ссылку источника. Если действующая ссылка уже есть — отказ: старую ссылку заменяет только {@see reissueLink()}
	 * с подтверждением, чтобы случайный повторный клик не отозвал ссылку, уже отправленную школе.
	 *
	 * @return string Адрес формы с ключом — единственный раз.
	 *
	 * @throws CodedException
	 */
	public function issueLink( int $actorUserId, int $sourceId ): string {
		$eventId = $this->eventIdOf( $actorUserId, $sourceId );

		return $this->inTransactionWithRetry( function () use ( $actorUserId, $sourceId, $eventId ): string {
			$source = $this->lockSource( $sourceId, $eventId );
			if ( $this->tokens->hasActive( ExamTokenPurpose::Invitation, $sourceId ) ) {
				throw new CodedException( ErrorCode::ExamConflict, 'Ссылка уже создана: чтобы заменить её, перевыпустите.' );
			}

			return $this->publishKey( $actorUserId, $source, 'ссылка создана' );
		} );
	}

	/**
	 * Заменяет ссылку: прежний ключ отзывается сразу, поколение растёт — открытые по старой ссылке формы теряют доступ.
	 * Оплаченные записи и действующие брони не затрагиваются.
	 *
	 * @return string Адрес формы с новым ключом — единственный раз.
	 *
	 * @throws CodedException
	 */
	public function reissueLink( int $actorUserId, int $sourceId ): string {
		$eventId = $this->eventIdOf( $actorUserId, $sourceId );

		return $this->inTransactionWithRetry( function () use ( $actorUserId, $sourceId, $eventId ): string {
			$source = $this->lockSource( $sourceId, $eventId );

			return $this->publishKey( $actorUserId, $source, 'ссылка перевыпущена' );
		} );
	}

	/**
	 * Отзывает ссылку без выпуска новой: новые заявки по ней невозможны, оплаченные записи и действующие брони остаются.
	 *
	 * @throws CodedException
	 */
	public function revokeLink( int $actorUserId, int $sourceId ): void {
		$eventId = $this->eventIdOf( $actorUserId, $sourceId );

		$this->inTransactionWithRetry( function () use ( $actorUserId, $sourceId, $eventId ): void {
			$source = $this->lockSource( $sourceId, $eventId );
			$this->tokens->revoke( ExamTokenPurpose::Invitation, $sourceId );
			$this->guestSessions->revokeBySource( $sourceId );
			$this->sources->setKeyRevokedAt( $sourceId, $this->time->nowUtc() );
			$this->audit( $actorUserId, OperationType::Update, LogEvent::ExamSourceUpdated, $sourceId, $source->schoolName . ': ссылка отозвана' );
		} );
	}

	/** Включает или отключает источник (отключённый новых заявок не принимает). @throws CodedException */
	public function setActive( int $actorUserId, int $sourceId, bool $active ): void {
		$eventId = $this->eventIdOf( $actorUserId, $sourceId );

		$this->inTransactionWithRetry( function () use ( $actorUserId, $sourceId, $eventId, $active ): void {
			$source = $this->lockSource( $sourceId, $eventId );
			if ( $source->isActive !== $active && ! $this->sources->update( $sourceId, array( 'is_active' => $active ? 1 : 0 ), $source->version ) ) {
				throw $this->stale();
			}
			$this->audit( $actorUserId, OperationType::Update, LogEvent::ExamSourceUpdated, $sourceId, $source->schoolName . ( $active ? ': включён' : ': отключён' ) );
		} );
	}

	// ---------------------------------------------------------------------------------------------------------------------------------

	/** Выпускает ключ под блокировкой источника: прежний отзывается, поколение растёт, отметка отзыва снимается. */
	private function publishKey( int $actorUserId, ExamSourceDTO $source, string $auditNote ): string {
		$event = $this->events->find( $source->eventId );
		$plain = $this->tokens->issue( ExamTokenPurpose::Invitation, $source->id, $actorUserId, $event?->registrationClosesAt );
		// Открытые по прежней ссылке формы после обновления страницы дают 404.
		$this->guestSessions->revokeBySource( $source->id );
		$this->sources->bumpGeneration( $source->id );
		$this->sources->setKeyRevokedAt( $source->id, null );
		$this->audit( $actorUserId, OperationType::Update, LogEvent::ExamSourceUpdated, $source->id, $source->schoolName . ': ' . $auditNote );

		return $this->signupUrl( $plain );
	}

	/** Адрес формы гостя с ключом. Единственное место, где собирается ссылка. */
	private function signupUrl( string $plain ): string {
		return (string) add_query_arg( array( 'k' => $plain ), PageRoutes::ExamSignup->url() );
	}

	/** @return array<string, mixed> */
	private function payload( ExamSourceDTO $source ): array {
		return array(
			'id'           => $source->id,
			'school_name'  => $source->schoolName,
			'teacher_name' => $source->teacherName,
			'grade'        => $source->grade,
			'is_active'    => $source->isActive,
			'has_link'     => $this->tokens->hasActive( ExamTokenPurpose::Invitation, $source->id ),
			'generation'   => $source->keyGeneration,
			'active_holds' => $this->applications->countHeldBySource( $source->id ),
			'version'      => $source->version,
		);
	}

	/** Направление проведения — направление формата его основного варианта: от него зависит допустимый класс. */
	private function directionOf( ExamEventDTO $event ): ExamDirection {
		$assessment = null !== $event->defaultAssessmentId ? $this->assessments->get( $event->defaultAssessmentId ) : null;
		$format     = null !== $assessment ? $this->formats->for( $assessment->kind ) : null;
		if ( null === $format ) {
			throw new CodedException( ErrorCode::ExamConflict, 'Выберите основной вариант проведения: от него зависит класс.' );
		}

		return $format->direction;
	}

	/** Проведение, которым пользователь вправе управлять, плюс право на гостей. @throws CodedException */
	private function requireEvent( int $actorUserId, int $eventId ): ExamEventDTO {
		$event = $this->events->find( $eventId );
		if ( null === $event || ! $this->guard->canManageEventGuests( $actorUserId, $event ) ) {
			throw new CodedException( ErrorCode::ExamAccess, 'Нет доступа к этому проведению.' );
		}

		return $event;
	}

	/** Проведение источника, прочитанное до транзакции (для проверки права и порядка блокировок). @throws CodedException */
	private function eventIdOf( int $actorUserId, int $sourceId ): int {
		$source = $this->sources->find( $sourceId );
		if ( null === $source ) {
			throw new CodedException( ErrorCode::ExamAccess, 'Источник не найден.' );
		}
		$this->requireEvent( $actorUserId, $source->eventId );

		return $source->eventId;
	}

	/** Блокирующее чтение источника — первый оператор транзакции. @throws CodedException */
	private function lockSource( int $sourceId, int $eventId ): ExamSourceDTO {
		$source = $this->sources->findForUpdate( $sourceId );
		if ( null === $source || $source->eventId !== $eventId ) {
			throw new CodedException( ErrorCode::ExamAccess, 'Источник не найден.' );
		}

		return $source;
	}

	private function reload( int $sourceId ): ExamSourceDTO {
		$source = $this->sources->find( $sourceId );
		if ( null === $source ) {
			throw new \RuntimeException( 'Источник не найден после сохранения.' );
		}

		return $source;
	}

	private function audit( int $actorUserId, OperationType $operation, LogEvent $event, int $sourceId, string $label ): void {
		$this->logEvents->dispatch( $event, new EntityChangedEvent( $actorUserId, $operation, EntityType::ExamSource, $sourceId, $label ) );
	}

	private function stale(): CodedException {
		return new CodedException( ErrorCode::ExamStale, 'Данные изменились: обновите страницу и повторите.' );
	}

	/** Строка из формы: края обрезаны, внутренние пробелы схлопнуты. */
	private function clean( string $value ): string {
		return trim( (string) preg_replace( '/\s+/u', ' ', $value ) );
	}
}
