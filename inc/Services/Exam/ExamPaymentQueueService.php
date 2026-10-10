<?php

declare( strict_types=1 );

namespace Inc\Services\Exam;

use Inc\DTO\Exam\ExamGuestApplicationDTO;
use Inc\DTO\Exam\ExamManualResolutionDTO;
use Inc\Enums\Exam\ExamSessionStatus;
use Inc\Enums\Exam\GuestApplicationState;
use Inc\Repositories\WPDBRepositories\ExamEventRepository;
use Inc\Repositories\WPDBRepositories\ExamGuestApplicationRepository;
use Inc\Repositories\WPDBRepositories\ExamManualResolutionRepository;
use Inc\Repositories\WPDBRepositories\ExamParticipantRepository;
use Inc\Repositories\WPDBRepositories\ExamPaymentLinkRepository;
use Inc\Repositories\WPDBRepositories\ExamSessionRepository;

/**
 * Очередь «Оплачено, требуется помощь» (этап 8.8.3): оплаченные гостевые заявки, которым не хватило места, и уже урегулированные.
 *
 * Только чтение. Видимость — по проведению ({@see ExamAccessGuard::canResolvePayments()}): офис и администратор видят всё,
 * преподаватель — свои проведения. В строке — гость, проведение, сеанс заявки, заказ и сумма, причина, время последней сверки,
 * ответственный за проведение и сеансы того же проведения со свободным местом для переноса. Контактов гостя здесь нет.
 */
class ExamPaymentQueueService {

	public const TAB_NEEDS_HELP = 'needs_help';
	public const TAB_RESOLVED   = 'resolved';

	public function __construct(
		private readonly ExamGuestApplicationRepository $applications,
		private readonly ExamManualResolutionRepository $resolutions,
		private readonly ExamPaymentLinkRepository $links,
		private readonly ExamEventRepository $events,
		private readonly ExamSessionRepository $sessions,
		private readonly ExamParticipantRepository $participants,
		private readonly GuestParticipantMaterializer $guestData,
		private readonly ExamAccessGuard $guard,
		private readonly ExamTime $time,
	) {}

	/**
	 * @return array{items: list<array<string, mixed>>}
	 */
	public function list( int $actorUserId, string $tab ): array {
		return array(
			'items' => self::TAB_RESOLVED === $tab ? $this->resolved( $actorUserId ) : $this->needsHelp( $actorUserId ),
		);
	}

	/** @return list<array<string, mixed>> */
	private function needsHelp( int $actorUserId ): array {
		$items = array();
		foreach ( $this->applications->listByState( GuestApplicationState::PaidNeedsResolution->value ) as $application ) {
			$event = $this->events->find( $application->eventId );
			if ( null === $event || ! $this->guard->canResolvePayments( $actorUserId, $event ) ) {
				continue;
			}

			$items[] = $this->base( $application, $event->title, $event->ownerUserId ) + array(
				'reason'  => $this->reasonFor( $application ),
				'targets' => $this->targets( $application ),
			);
		}

		return $items;
	}

	/** @return list<array<string, mixed>> */
	private function resolved( int $actorUserId ): array {
		$items = array();
		foreach ( $this->resolutions->listRecentForApplications() as $resolution ) {
			$application = null !== $resolution->applicationId ? $this->applications->find( $resolution->applicationId ) : null;
			$event       = null !== $application ? $this->events->find( $application->eventId ) : null;
			if ( null === $application || null === $event || ! $this->guard->canResolvePayments( $actorUserId, $event ) ) {
				continue;
			}

			$items[] = $this->base( $application, $event->title, $event->ownerUserId ) + $this->resolutionView( $resolution );
		}

		return $items;
	}

	/** @return array<string, mixed> */
	private function base( ExamGuestApplicationDTO $application, string $eventTitle, int $ownerId ): array {
		$link    = $this->links->findByApplication( $application->id )[0] ?? null;
		$session = $this->sessions->find( $application->sessionId );
		$owner   = $ownerId > 0 ? get_userdata( $ownerId ) : false;

		return array(
			'application_id'   => $application->id,
			'guest'            => $this->guestName( $application ),
			'event_title'      => $eventTitle,
			'session'          => null !== $session ? $this->sessionLabel( $session->scheduledAt ) : '',
			'order_id'         => $link?->wcOrderId,
			'amount'           => $link?->amount,
			'currency'         => $link?->currency,
			'last_reconciled'  => null !== $link?->lastReconciledAt ? $this->time->toLocal( $link->lastReconciledAt ) : null,
			'responsible_name' => $owner ? (string) $owner->display_name : '',
		);
	}

	/** Причина считается по текущему состоянию сеанса: отдельно её никто не хранит, а картина меняется (место могло освободиться). */
	private function reasonFor( ExamGuestApplicationDTO $application ): string {
		$session = $this->sessions->find( $application->sessionId );
		if ( null === $session || ExamSessionStatus::Cancelled->value === $session->status ) {
			return 'Сеанс отменён.';
		}
		if ( $session->scheduledAt <= $this->time->nowUtc() ) {
			return 'Сеанс уже начался.';
		}
		if ( $session->occupiedCount >= $session->capacity ) {
			return 'В сеансе не осталось мест.';
		}

		return 'Не удалось подтвердить запись: проверьте, нет ли у гостя другой записи.';
	}

	/**
	 * Сеансы того же проведения, куда можно перенести: открыты, не закончились, есть свободное место; свой сеанс заявки не предлагается.
	 *
	 * @return list<array{id: int, label: string, free: int}>
	 */
	private function targets( ExamGuestApplicationDTO $application ): array {
		$now     = $this->time->nowUtc();
		$targets = array();
		foreach ( $this->sessions->findByEvent( $application->eventId ) as $session ) {
			$free = $session->capacity - $session->occupiedCount;
			if ( $session->id === $application->sessionId || ExamSessionStatus::Open->value !== $session->status || $session->plannedEndAt <= $now || $free <= 0 ) {
				continue;
			}
			$targets[] = array( 'id' => $session->id, 'label' => $this->sessionLabel( $session->scheduledAt ), 'free' => $free );
		}

		return $targets;
	}

	/** @return array<string, mixed> */
	private function resolutionView( ExamManualResolutionDTO $resolution ): array {
		$actor = get_userdata( $resolution->actorUserId );
		$new   = null !== $resolution->newSessionId ? $this->sessions->find( $resolution->newSessionId ) : null;

		return array(
			'kind'        => $resolution->kind,
			'kind_label'  => \Inc\Enums\Exam\ManualResolutionKind::tryFrom( $resolution->kind )?->label() ?? $resolution->kind,
			'reason'      => $resolution->reason,
			'resolved_by' => $actor ? (string) $actor->display_name : '',
			'resolved_at' => $this->time->toLocal( $resolution->createdAt ),
			'refund'      => $resolution->amount,
			'new_session' => null !== $new ? $this->sessionLabel( $new->scheduledAt ) : null,
		);
	}

	private function guestName( ExamGuestApplicationDTO $application ): string {
		$participant = null !== $application->participantId ? $this->participants->find( $application->participantId ) : null;
		$name        = null !== $participant ? $this->guestData->displayName( $participant ) : null;

		return $name ?? ( '' !== $this->guestData->draftName( $application ) ? $this->guestData->draftName( $application ) : 'Гость' );
	}

	private function sessionLabel( string $scheduledAtUtc ): string {
		$local = $this->time->toLocal( $scheduledAtUtc );

		return substr( $local, 8, 2 ) . '.' . substr( $local, 5, 2 ) . '.' . substr( $local, 0, 4 ) . ' ' . substr( $local, 11, 5 );
	}
}
