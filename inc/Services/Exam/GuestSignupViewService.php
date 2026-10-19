<?php
/**
 * Данные публичной страницы записи гостя на экзамен (этап 11a.3.1): источник, проведение, сеансы, согласия, цена, контакты.
 *
 * @package FS_LMS
 */

declare( strict_types=1 );

namespace Inc\Services\Exam;

use Inc\Enums\Exam\ExamEventStatus;

use Inc\DTO\Exam\ExamEventDTO;
use Inc\DTO\Exam\ExamSessionDTO;
use Inc\DTO\Exam\ExamSourceDTO;
use Inc\Enums\Exam\ExamDirection;
use Inc\Enums\Exam\ExamSessionStatus;
use Inc\Managers\Assessment\AssessmentManager;
use Inc\Managers\Wp\PostManager;
use Inc\Repositories\WPDBRepositories\ExamEventRepository;
use Inc\Repositories\WPDBRepositories\ExamSessionRepository;
use Inc\Repositories\WPDBRepositories\RoomRepository;
use Inc\Managers\Woo\WooCommerceManager;
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

	/** Получает расписание, согласия и контакты для формы без создания заявки. */
	public function __construct(
		private readonly ExamEventRepository $events,
		private readonly ExamSessionRepository $sessions,
		private readonly RoomRepository $rooms,
		private readonly AssessmentManager $assessments,
		private readonly ExamFormatRegistry $formats,
		private readonly WooCommerceManager $woo,
		private readonly PluginConfig $config,
		private readonly ConsentService $consents,
		private readonly CenterContactsService $contacts,
		private readonly FormGuardService $guard,
		private readonly ExamTime $time,
		private readonly PostManager $posts,
		private readonly GuestPublicSignupService $publicSignup,
	) {}

	/**
	 * Готовит форму записи по источнику, включая сеансы, согласия и контакты центра.
	 *
	 * @return array<string, mixed>
	 */
	public function build( ExamSourceDTO $source ): array {
		$event    = $this->events->find( $source->eventId );
		$contacts = $this->contacts->get();
		$base     = array(
			'state'    => 'closed',
			'source'   => array(
				'school'  => $source->schoolName,
				'grade'   => $source->grade,
				'teacher' => $source->teacherName,
				'public'  => $source->isPublic(),
				'free'    => $source->isFree(),
				// Скидка по ссылке преподавателя, %: показывается гостю явно (0 — полная цена, у открытой записи не бывает).
				'discount' => $source->effectiveDiscount(),
			),
			'event'    => array( 'title' => $event->title ?? '' ),
			'contacts' => $contacts,
			'sessions' => array(),
		);

		$nowUtc = $this->time->nowUtc();
		if ( null === $event || ExamEventStatus::Published->value !== $event->status || ! $event->guestRegistrationEnabled || ! $this->registrationOpen( $event, $nowUtc ) ) {
			return $base;
		}

		$sessions = $this->sessionCards( $event, $nowUtc, $source );
		// Бесплатная ссылка преподавателя: цены и товара магазина не требует; частичная скидка — цена товара со скидкой.
		$price    = $source->isFree() ? array( 'html' => 'Бесплатно', 'amount' => '0' ) : $this->price( $source->grade, $source->effectiveDiscount() );
		$base     = array_merge(
			$base,
			array(
				'sessions'       => $sessions,
				'direction'      => $this->directionLabel( $event ),
				'price'          => $price,
				'address'        => $this->contacts->addressWithoutRoom(),
				'hold_minutes'   => $this->config->examHoldMinutes(),
				'retention_days' => $this->config->examGuestRetentionDays(),
				'consents'       => $this->consentList( $source->isPublic() ),
				'honeypot'       => $this->guard->honeypotField(),
				'form_token'     => $this->guard->timestampToken(),
			)
		);

		if ( null === $price || array() === $base['consents'] || ! $this->hasPdConsent( $base['consents'] ) ) {
			return array_merge( $base, array( 'state' => 'unavailable' ) );
		}
		if ( array() === array_filter( $sessions, static fn ( array $s ): bool => $s['selectable'] ) ) {
			return array_merge( $base, array( 'state' => 'full' ) );
		}

		return array_merge( $base, array( 'state' => 'form' ) );
	}

	/**
	 * Страница открытой записи без приглашения: выбор класса. `$gradeClosed` — гость выбрал класс, на который записи сейчас нет.
	 *
	 * @return array{options: list<array{grade: int, label: string, available: bool, url: string}>, grade_closed: bool, contacts: array<string, string>}
	 */
	public function publicChooser( bool $gradeClosed ): array {
		return array(
			'options'      => $this->publicSignup->options(),
			'grade_closed' => $gradeClosed,
			'contacts'     => $this->contacts->get(),
		);
	}

	/**
	 * Проверяет UTC-окно гостевой записи с исключительной верхней границей.
	 */
	private function registrationOpen( ExamEventDTO $event, string $nowUtc ): bool {
		return ( null === $event->registrationOpensAt || $event->registrationOpensAt <= $nowUtc )
			&& ( null === $event->registrationClosesAt || $event->registrationClosesAt > $nowUtc );
	}

	/**
	 * Будущие неотменённые сеансы: дата, день недели, время, кабинет, свободные места; заполненные видны, но не выбираются.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function sessionCards( ExamEventDTO $event, string $nowUtc, ExamSourceDTO $source ): array {
		$cards = array();
		foreach ( $this->sessions->findByEvent( $event->id ) as $session ) {
			if ( ExamSessionStatus::Open->value !== $session->status || $session->scheduledAt <= $nowUtc || ! $source->allowsSession( $session->id ) ) {
				continue;
			}
			$cards[] = $this->card( $session );
		}

		return $cards;
	}

	/**
	 * Готовит подписи времени и доступность сеанса для выбора в форме.
	 *
	 * @return array<string, mixed>
	 */
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

	/**
	 * Цена и товар — из WooCommerce; null, если магазин или товар недоступны.
	 *
	 * @return array{html: string, amount: string, old_html?: string}|null
	 */
	private function price( int $grade, int $discount = 0 ): ?array {
		$product = $this->woo->product( $this->config->examProductId( $grade ) );
		if ( null === $product || ! $product['purchasable'] || (float) $product['price'] <= 0.0 ) {
			return null;
		}

		if ( $discount <= 0 || $discount >= 100 ) {
			return array(
				'html'   => $this->woo->formatPrice( $product['price'] ),
				'amount' => $product['price'],
			);
		}

		// Та же формула, что в корзине (`WooExamAdapter::discountedPrice()`): форма и счёт не расходятся.
		$discounted = (string) round( (float) $product['price'] * ( 100 - $discount ) / 100, 2 );

		return array(
			'html'     => $this->woo->formatPrice( $discounted ),
			'amount'   => $discounted,
			'old_html' => $this->woo->formatPrice( $product['price'] ),
		);
	}

	/**
	 * Определяет направление по формату варианта проведения для формы гостя.
	 */
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
	private function consentList( bool $isPublic = false ): array {
		$list = array();
		foreach ( self::CONSENTS as $key => $required ) {
			// Открытая запись не связана со школой: передавать результат преподавателю некому.
			if ( $isPublic && GuestApplicationService::CONSENT_TRANSFER === $key ) {
				continue;
			}
			$page = $this->consents->getPageForType( $key );
			if ( null === $page ) {
				continue;
			}
			$list[] = array(
				'key'      => $key,
				'title'    => $this->consents->getDefinitionName( $key ),
				'url'      => $this->posts->getPermalink( $page->ID ),
				'required' => $required,
			);
		}

		return $list;
	}

	/**
	 * Проверяет наличие согласия на обработку персональных данных в списке формы.
	 *
	 * @param list<array{key: string}> $consents
	 */
	private function hasPdConsent( array $consents ): bool {
		foreach ( $consents as $consent ) {
			if ( GuestApplicationService::CONSENT_PD === $consent['key'] ) {
				return true;
			}
		}

		return false;
	}
}
