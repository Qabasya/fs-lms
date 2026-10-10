<?php

declare( strict_types=1 );

namespace Inc\Services\Exam;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Exam\ExamEventDTO;
use Inc\DTO\Exam\ExamParticipationDTO;
use Inc\DTO\Exam\ExamReportDTO;
use Inc\DTO\Log\Events\EntityChangedEvent;
use Inc\Enums\Access\Capability;
use Inc\Enums\Assessment\AttemptStatus;
use Inc\Enums\Exam\ExamAudience;
use Inc\Enums\Exam\ExamTokenPurpose;
use Inc\Enums\Log\EntityType;
use Inc\Enums\Log\ErrorCode;
use Inc\Enums\Log\LogEvent;
use Inc\Enums\Log\OperationType;
use Inc\Enums\Wp\PageRoutes;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Repositories\WPDBRepositories\ConsentRepository;
use Inc\Repositories\WPDBRepositories\ExamEventRepository;
use Inc\Repositories\WPDBRepositories\ExamParticipationRepository;
use Inc\Repositories\WPDBRepositories\ExamReportMemberRepository;
use Inc\Repositories\WPDBRepositories\ExamReportRepository;
use Inc\Repositories\WPDBRepositories\ExamSourceRepository;
use Inc\Services\Shared\PluginConfig;
use Inc\Shared\CodedException;
use Inc\Shared\Traits\TransactionRunner;

/**
 * Школьные отчёты (этап 12.2): именованная фиксированная выборка участий проведения с отдельным ключом доступа и сроком.
 *
 * Состав задаётся сотрудником и сам не пополняется: новый гость той же школы в отчёт не попадает. Ученик центра включается без гостевого
 * согласия, но только с утверждённым результатом; гость — только с действующим согласием на передачу (`pd_transfer`). Оплата и приглашение
 * согласием не считаются. Ключ отчёта (`ExamTokenPurpose::Report`) отделён от ключа приглашения и результата и школе доступа к ПД не даёт.
 */
class ExamReportService {

	use TransactionRunner;

	public const DEFAULT_DAYS = 90;
	public const MAX_DAYS     = 90;

	public function __construct(
		private readonly ExamReportRepository $reports,
		private readonly ExamReportMemberRepository $members,
		private readonly ExamParticipationRepository $participations,
		private readonly ExamEventRepository $events,
		private readonly ExamSourceRepository $sources,
		private readonly AssessmentAttemptRepository $attempts,
		private readonly ConsentRepository $consents,
		private readonly ExamAccessGuard $guard,
		private readonly ExamAccessTokenService $tokens,
		private readonly LogEventDispatcherInterface $logEvents,
		private readonly PluginConfig $config,
		private readonly ExamTime $time,
	) {}

	/**
	 * Отчёты проведения и причины, по которым участие нельзя включить (для режима выбора строк).
	 *
	 * @return array{reports: list<array<string, mixed>>, blocked: array<int, string>, sources: list<array{id: int, label: string}>}
	 *
	 * @throws CodedException
	 */
	public function overview( int $actorUserId, int $eventId ): array {
		$event = $this->requireEvent( $actorUserId, $eventId );

		$blocked = array();
		foreach ( $this->participations->findByEvent( $eventId ) as $participation ) {
			$reason = $this->canInclude( $participation, $eventId );
			if ( null !== $reason ) {
				$blocked[ $participation->id ] = $reason;
			}
		}

		return array(
			'reports' => array_map( fn ( ExamReportDTO $report ): array => $this->view( $report ), $this->reports->listByEvent( $event->id ) ),
			'blocked' => $blocked,
			'sources' => array_map(
				static fn ( $source ): array => array( 'id' => $source->id, 'label' => $source->schoolName . ' · ' . $source->teacherName ),
				$this->sources->listByEvent( $event->id )
			),
		);
	}

	/**
	 * Создаёт отчёт из выбранных участий. Хотя бы одно не прошедшее проверку — отказ всей операции с перечнем причин.
	 *
	 * @param list<int> $participationIds
	 *
	 * @throws CodedException
	 */
	public function create( int $actorUserId, int $eventId, string $title, array $participationIds, ?int $recipientSourceId, ?int $days ): ExamReportDTO {
		$event = $this->requireEvent( $actorUserId, $eventId );

		$title = trim( (string) preg_replace( '/\s+/u', ' ', $title ) );
		if ( '' === $title ) {
			throw new CodedException( ErrorCode::ExamConflict, 'Укажите название отчёта.' );
		}
		$participationIds = array_values( array_unique( array_filter( array_map( 'intval', $participationIds ) ) ) );
		if ( array() === $participationIds ) {
			throw new CodedException( ErrorCode::ExamConflict, 'Выберите хотя бы одного участника.' );
		}
		$days = $days ?? self::DEFAULT_DAYS;
		if ( $days < 1 || $days > self::MAX_DAYS ) {
			throw new CodedException( ErrorCode::ExamConflict, sprintf( 'Срок отчёта — от 1 до %d дней.', self::MAX_DAYS ) );
		}
		if ( null !== $recipientSourceId ) {
			$source = $this->sources->find( $recipientSourceId );
			if ( null === $source || $source->eventId !== $eventId ) {
				throw new CodedException( ErrorCode::ExamConflict, 'Получатель не из этого проведения.' );
			}
		}

		$consentRefs = $this->assertAllIncludable( $participationIds, $eventId );

		$now       = $this->time->nowUtc();
		$reportId  = $this->inTransactionWithRetry( function () use ( $actorUserId, $eventId, $title, $recipientSourceId, $days, $event, $participationIds, $consentRefs, $now ): int {
			$id = $this->reports->insert( array(
				'event_id'            => $eventId,
				'title'               => $title,
				'owner_user_id'       => $actorUserId,
				'recipient_source_id' => $recipientSourceId,
				'expires_at'          => $this->expiryFor( $event, $days ),
				'version'             => 1,
				'created_at'          => $now,
			) );
			foreach ( $participationIds as $participationId ) {
				$this->members->add( $id, $participationId, $consentRefs[ $participationId ] ?? null, $now );
			}

			return $id;
		} );

		$this->audit( $actorUserId, OperationType::Create, $reportId, sprintf( 'отчёт «%s»: %d участников', $title, count( $participationIds ) ) );

		return $this->reload( $reportId );
	}

	/**
	 * Включено ли участие в отчёт проведения: `null` — можно, иначе причина отказа.
	 */
	public function canInclude( ExamParticipationDTO $participation, int $eventId ): ?string {
		if ( $participation->eventId !== $eventId ) {
			return 'Участник не из этого проведения.';
		}

		$attempt = null !== $participation->currentAttemptId ? $this->attempts->find( $participation->currentAttemptId ) : null;
		if ( null === $attempt || AttemptStatus::InProgress === $attempt->status ) {
			return 'Работа не сдана.';
		}

		if ( ExamAudience::Student->value === $participation->audience ) {
			return $attempt->isApproved() ? null : 'Результат ученика не утверждён.';
		}

		if ( ! $participation->transferAllowed ) {
			return 'Нет согласия на передачу результата школе.';
		}
		$ref = $this->transferConsentRef( $participation );
		$consent = null !== $ref ? $this->consents->find( $ref ) : null;
		if ( null === $consent || null !== $consent->withdrawnAt ) {
			return 'Согласие отозвано.';
		}

		return null;
	}

	/**
	 * Добавляет участие в отчёт. Версия — та, что видела вкладка; устаревшая — `ExamStale`.
	 *
	 * @throws CodedException
	 */
	public function addMember( int $actorUserId, int $reportId, int $participationId, int $expectedVersion ): ExamReportDTO {
		$report = $this->requireReport( $actorUserId, $reportId );
		$participation = $this->participations->find( $participationId );
		if ( null === $participation ) {
			throw new CodedException( ErrorCode::ExamConflict, 'Участник не найден.' );
		}
		$reason = $this->canInclude( $participation, $report->eventId );
		if ( null !== $reason ) {
			throw new CodedException( ErrorCode::ExamConflict, $reason );
		}

		$this->assertActive( $report );
		$this->changeMembers( $report, $expectedVersion, fn (): bool => $this->members->add( $reportId, $participationId, $this->transferConsentRef( $participation ), $this->time->nowUtc() ) );
		$this->audit( $actorUserId, OperationType::Update, $reportId, sprintf( 'добавлено участие #%d', $participationId ) );

		return $this->reload( $reportId );
	}

	/** @throws CodedException */
	public function removeMember( int $actorUserId, int $reportId, int $participationId, int $expectedVersion ): ExamReportDTO {
		$report = $this->requireReport( $actorUserId, $reportId );
		$this->assertActive( $report );
		$this->changeMembers( $report, $expectedVersion, fn (): bool => $this->members->remove( $reportId, $participationId ) );
		$this->audit( $actorUserId, OperationType::Update, $reportId, sprintf( 'убрано участие #%d', $participationId ) );

		return $this->reload( $reportId );
	}

	/**
	 * Выдаёт ключ отчёта (прежний отзывается). Открытый ключ не хранится: «скопировать ещё раз» — это перевыпуск.
	 *
	 * @return string Адрес с ключом — единственный раз.
	 *
	 * @throws CodedException
	 */
	public function issueLink( int $actorUserId, int $reportId ): string {
		$report = $this->requireReport( $actorUserId, $reportId );
		$this->assertActive( $report );

		$plain = $this->tokens->issue( ExamTokenPurpose::Report, $reportId, $actorUserId, $report->expiresAt );
		$this->audit( $actorUserId, OperationType::Update, $reportId, 'ссылка выдана' );

		return PageRoutes::ExamReport->url() . '?k=' . $plain;
	}

	/** Отзыв отчёта: ключ перестаёт работать, отчёт помечается отозванным. @throws CodedException */
	public function revoke( int $actorUserId, int $reportId, int $expectedVersion ): ExamReportDTO {
		$report = $this->requireReport( $actorUserId, $reportId );
		if ( null === $report->revokedAt && ! $this->reports->setRevoked( $reportId, $this->time->nowUtc(), $expectedVersion ) ) {
			throw new CodedException( ErrorCode::ExamStale, 'Данные изменились: обновите страницу и повторите.' );
		}
		$this->tokens->revoke( ExamTokenPurpose::Report, $reportId );
		$this->audit( $actorUserId, OperationType::Update, $reportId, 'отчёт отозван' );

		return $this->reload( $reportId );
	}

	/** Отчёт сейчас открыт школе: не отозван и не истёк. */
	public function isOpen( ExamReportDTO $report ): bool {
		return null === $report->revokedAt && $report->expiresAt > $this->time->nowUtc();
	}

	/**
	 * Согласие на передачу из участия (`consent_refs` — JSON `{тип: id}`); null — нет.
	 */
	public function transferConsentRef( ExamParticipationDTO $participation ): ?int {
		$refs = null !== $participation->consentRefs ? json_decode( $participation->consentRefs, true ) : null;
		$id   = is_array( $refs ) ? (int) ( $refs['pd_transfer'] ?? 0 ) : 0;

		return $id > 0 ? $id : null;
	}

	/**
	 * Все ли участия проходят проверку; иначе отказ с перечнем причин. Возвращает согласия по участиям.
	 *
	 * @param list<int> $participationIds
	 *
	 * @return array<int, int|null>
	 *
	 * @throws CodedException
	 */
	private function assertAllIncludable( array $participationIds, int $eventId ): array {
		$problems = array();
		$refs     = array();
		foreach ( $participationIds as $participationId ) {
			$participation = $this->participations->find( $participationId );
			$reason        = null === $participation ? 'Участник не найден.' : $this->canInclude( $participation, $eventId );
			if ( null !== $reason ) {
				$problems[] = sprintf( '#%d: %s', $participationId, $reason );
				continue;
			}
			$refs[ $participationId ] = $this->transferConsentRef( $participation );
		}
		if ( array() !== $problems ) {
			throw new CodedException( ErrorCode::ExamConflict, 'В отчёт нельзя включить: ' . implode( ' ', $problems ) );
		}

		return $refs;
	}

	/**
	 * Изменение состава под версией отчёта: сначала версия (условный `UPDATE`), затем сама правка — в одной транзакции.
	 *
	 * @param callable():bool $change
	 */
	private function changeMembers( ExamReportDTO $report, int $expectedVersion, callable $change ): void {
		$this->inTransactionWithRetry( function () use ( $report, $expectedVersion, $change ): void {
			if ( ! $this->reports->bumpVersion( $report->id, $expectedVersion ) ) {
				throw new CodedException( ErrorCode::ExamStale, 'Данные изменились: обновите страницу и повторите.' );
			}
			$change();
		} );
	}

	/** Срок — `min( сейчас + дни, конец хранения данных проведения )`, UTC. */
	private function expiryFor( ExamEventDTO $event, int $days ): string {
		$byDays      = $this->time->addMinutes( $this->time->nowUtc(), $days * 1440 );
		$byRetention = $this->time->addMinutes( $this->time->endOfLocalDayUtc( $event->periodTo ), $this->config->examGuestRetentionDays() * 1440 );

		return min( $byDays, $byRetention );
	}

	/** @return array<string, mixed> */
	private function view( ExamReportDTO $report ): array {
		$state = null !== $report->revokedAt ? 'revoked' : ( $report->expiresAt <= $this->time->nowUtc() ? 'expired' : 'active' );

		return array(
			'id'                  => $report->id,
			'title'               => $report->title,
			'version'             => $report->version,
			'state'               => $state,
			'expires_at'          => $this->time->toLocal( $report->expiresAt ),
			'recipient_source_id' => $report->recipientSourceId,
			'participation_ids'   => $this->members->listParticipationIds( $report->id ),
			'link_issued'         => 'active' === $state && $this->tokens->hasActive( ExamTokenPurpose::Report, $report->id ),
		);
	}

	/** @throws CodedException */
	private function assertActive( ExamReportDTO $report ): void {
		if ( null !== $report->revokedAt ) {
			throw new CodedException( ErrorCode::ExamClosed, 'Отчёт отозван.' );
		}
	}

	/** @throws CodedException */
	private function requireEvent( int $actorUserId, int $eventId ): ExamEventDTO {
		$event = $this->events->find( $eventId );
		if ( null === $event || ! user_can( $actorUserId, Capability::ShareExamResults->value ) || ! $this->guard->canManageEvent( $actorUserId, $event ) ) {
			throw new CodedException( ErrorCode::ExamAccess, 'Проведение не найдено.' );
		}

		return $event;
	}

	/** @throws CodedException */
	private function requireReport( int $actorUserId, int $reportId ): ExamReportDTO {
		$report = $this->reports->find( $reportId );
		if ( null === $report ) {
			throw new CodedException( ErrorCode::ExamAccess, 'Отчёт не найден.' );
		}
		$this->requireEvent( $actorUserId, $report->eventId );

		return $report;
	}

	private function reload( int $reportId ): ExamReportDTO {
		$report = $this->reports->find( $reportId );
		if ( null === $report ) {
			throw new \RuntimeException( 'Отчёт не найден после сохранения.' );
		}

		return $report;
	}

	private function audit( int $actorUserId, OperationType $operation, int $reportId, string $label ): void {
		$this->logEvents->dispatch( LogEvent::ExamReportChanged, new EntityChangedEvent( $actorUserId, $operation, EntityType::ExamReport, $reportId, $label ) );
	}
}
