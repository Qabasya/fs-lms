<?php

declare( strict_types=1 );

namespace Inc\Services\Exam;

use Inc\DTO\Exam\ExamEventDTO;
use Inc\DTO\Exam\ExamSessionDTO;
use Inc\DTO\Exam\ExamSourceDTO;
use Inc\Enums\Exam\ExamDirection;
use Inc\Enums\Exam\ExamSessionStatus;
use Inc\Managers\Assessment\AssessmentManager;
use Inc\Repositories\WPDBRepositories\ExamEventRepository;
use Inc\Repositories\WPDBRepositories\ExamSessionRepository;
use Inc\Repositories\WPDBRepositories\RoomRepository;
use Inc\Services\Exam\Payment\WooGateway;
use Inc\Services\Person\ConsentService;
use Inc\Services\Security\FormGuardService;
use Inc\Services\Shared\CenterContactsService;
use Inc\Services\Shared\PluginConfig;

/**
 * Данные публичной страницы записи гостя на экзамен (этап 11a.3.1): источник, проведение, сеансы, согласия, цена, контакты.
 *
 * Все тексты и числа готовит сервер: **цена — только из WooCommerce** (товар класса направления), не из формы и не из настроек LMS.
 * Состояние страницы: `form` — форма; `full` — мест нет; `closed` — запись закрыта; `unavailable` — магазин или товар недоступен.
 * Во всех состояниях, кроме `form`, кнопки оплаты нет — только контакт центра.
 */
class GuestSignupViewService {

	/** Согласия формы: ключ => обязательное. */
	private const CONSENTS = array(
		GuestApplicationService::CONSENT_PD        => true,
		GuestApplicationService::CONSENT_TRANSFER  => false,
		GuestApplicationService::CONSENT_MARKETING => false,
	);

	public function __construct(
		private readonly ExamEventRepository $events,
		private readonly ExamSessionRepository $sessions,
		private readonly RoomRepository $rooms,
		private readonly AssessmentManager $assessments,
		private readonly ExamFormatRegistry $formats,
		private readonly WooGateway $woo,
		private readonly PluginConfig $config,
		private readonly ConsentService $consents,
		private readonly CenterContactsService $contacts,
		private readonly FormGuardService $guard,
		private readonly ExamTime $time,
	) {}

	/**
	 * @return array<string, mixed>
	 */
	public function build( ExamSourceDTO $source ): array {
		$event    = $this->events->find( $source->eventId );
		$contacts = $this->contacts->get();
		$base     = array(
			'state'    => 'closed',
			'source'   => array( 'school' => $source->schoolName, 'grade' => $source->grade, 'teacher' => $source->teacherName ),
			'event'    => array( 'title' => $event->title ?? '' ),
			'contacts' => $contacts,
			'sessions' => array(),
		);

		$nowUtc = $this->time->nowUtc();
		if ( null === $event || 'published' !== $event->status || ! $event->guestRegistrationEnabled || ! $this->registrationOpen( $event, $nowUtc ) ) {
			return $base;
		}

		$sessions = $this->sessionCards( $event, $nowUtc );
		$price    = $this->price( $source->grade );
		$base     = array_merge( $base, array(
			'sessions'           => $sessions,
			'direction'          => $this->directionLabel( $event ),
			'price'              => $price,
			'address'            => $this->contacts->addressWithoutRoom(),
			'hold_minutes'       => $this->config->examHoldMinutes(),
			'retention_days'     => $this->config->examGuestRetentionDays(),
			'consents'           => $this->consentList(),
			'honeypot'           => $this->guard->honeypotField(),
			'form_token'         => $this->guard->timestampToken(),
		) );

		if ( null === $price || array() === $base['consents'] || ! $this->hasPdConsent( $base['consents'] ) ) {
			return array_merge( $base, array( 'state' => 'unavailable' ) );
		}
		if ( array() === array_filter( $sessions, static fn ( array $s ): bool => $s['selectable'] ) ) {
			return array_merge( $base, array( 'state' => 'full' ) );
		}

		return array_merge( $base, array( 'state' => 'form' ) );
	}

	private function registrationOpen( ExamEventDTO $event, string $nowUtc ): bool {
		return ( null === $event->registrationOpensAt || $event->registrationOpensAt <= $nowUtc )
			&& ( null === $event->registrationClosesAt || $event->registrationClosesAt > $nowUtc );
	}

	/**
	 * Будущие неотменённые сеансы: дата, день недели, время, кабинет, свободные места; заполненные видны, но не выбираются.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function sessionCards( ExamEventDTO $event, string $nowUtc ): array {
		$cards = array();
		foreach ( $this->sessions->findByEvent( $event->id ) as $session ) {
			if ( ExamSessionStatus::Open->value !== $session->status || $session->scheduledAt <= $nowUtc ) {
				continue;
			}
			$cards[] = $this->card( $session );
		}

		return $cards;
	}

	/** @return array<string, mixed> */
	private function card( ExamSessionDTO $session ): array {
		$start = $this->time->toLocal( $session->scheduledAt );
		$end   = $this->time->toLocal( $session->plannedEndAt );
		$free  = $session->freeSeats();

		return array(
			'id'         => $session->id,
			'date'       => substr( $start, 0, 10 ),
			'weekday'    => mb_strtolower( wp_date( 'l', (int) strtotime( $session->scheduledAt . ' UTC' ) ) ),
			'time_start' => substr( $start, 11, 5 ),
			'time_end'   => substr( $end, 11, 5 ),
			'room'       => $this->rooms->find( $session->roomId )->name ?? '',
			'free'       => $free,
			'selectable' => $free > 0,
		);
	}

	/** Цена и товар — из WooCommerce; null, если магазин или товар недоступны. @return array{html: string, amount: string}|null */
	private function price( int $grade ): ?array {
		$product = $this->woo->product( $this->config->examProductId( $grade ) );
		if ( null === $product || ! $product['purchasable'] || (float) $product['price'] <= 0.0 ) {
			return null;
		}

		return array( 'html' => $this->woo->formatPrice( $product['price'] ), 'amount' => $product['price'] );
	}

	private function directionLabel( ExamEventDTO $event ): string {
		$assessmentId = $event->defaultAssessmentId;
		if ( null !== $assessmentId ) {
			$kind   = $this->assessments->get( $assessmentId )?->kind;
			$format = null !== $kind ? $this->formats->for( $kind ) : null;
			if ( null !== $format ) {
				return $format->direction->label();
			}
		}

		return ExamDirection::Ege->label();
	}

	/**
	 * Согласия формы со ссылкой «Прочитать»: показываются те, чья страница создана.
	 *
	 * @return list<array{key: string, title: string, url: string, required: bool}>
	 */
	private function consentList(): array {
		$list = array();
		foreach ( self::CONSENTS as $key => $required ) {
			$page = $this->consents->getPageForType( $key );
			if ( null === $page ) {
				continue;
			}
			$list[] = array(
				'key'      => $key,
				'title'    => $this->consents->getDefinitionName( $key ),
				'url'      => (string) get_permalink( $page ),
				'required' => $required,
			);
		}

		return $list;
	}

	/** @param list<array{key: string}> $consents */
	private function hasPdConsent( array $consents ): bool {
		foreach ( $consents as $consent ) {
			if ( GuestApplicationService::CONSENT_PD === $consent['key'] ) {
				return true;
			}
		}

		return false;
	}
}
