<?php

declare( strict_types=1 );

namespace Inc\Services\Exam;

use Inc\DTO\Exam\ExamGuestApplicationDTO;
use Inc\DTO\RequestContextDTO;
use Inc\Enums\Exam\ExamTokenPurpose;
use Inc\Enums\Log\ErrorCode;
use Inc\Enums\Wp\PageRoutes;
use Inc\Repositories\WPDBRepositories\ExamEventRepository;
use Inc\Repositories\WPDBRepositories\ExamGuestApplicationRepository;
use Inc\Repositories\WPDBRepositories\ExamParticipantRepository;
use Inc\Repositories\WPDBRepositories\ExamSessionRepository;
use Inc\Repositories\WPDBRepositories\ExamSourceRepository;
use Inc\Shared\CodedException;

/**
 * «Добавить гостя на месте» (этап 11a.7): сотрудник оформляет заявку на площадке, платит гость **со своего устройства** по ссылке.
 *
 * Корзина WooCommerce привязана к сессии браузера, поэтому заявку создаёт сотрудник, а в корзину позиция попадает, когда гость открывает
 * ссылку на оплату (`?pay=`). **Обхода оплаты нет**: ни «отметить оплаченным», ни «записать бесплатно», ни «наличные» — бесплатный допуск
 * возможен только купоном магазина. Срок брони ограничен плановым концом сеанса (`ExamHoldService::capture()`); лимиты по IP к заявке
 * сотрудника не применяются, лимит источника — применяется.
 */
class GuestOnSiteService {

	public function __construct(
		private readonly GuestApplicationService $applications,
		private readonly ExamAccessGuard $guard,
		private readonly ExamEventRepository $events,
		private readonly ExamSessionRepository $sessions,
		private readonly ExamSourceRepository $sources,
		private readonly ExamGuestApplicationRepository $applicationRepo,
		private readonly ExamParticipantRepository $participants,
		private readonly GuestParticipantMaterializer $materializer,
		private readonly ExamAccessTokenService $tokens,
		private readonly ExamTime $time,
	) {}

	/**
	 * @param array<string, mixed> $form Поля формы (как у гостевой формы); согласия сотрудник отмечает со слов участника.
	 *
	 * @return array{status: 'created', application_id: int, pay_url: string, hold_expires_at: string, seconds_left: int}
	 *         |array{status: 'needs_confirmation', candidates: list<array{name: string, participation_id: int, match: string}>}
	 *
	 * @throws CodedException
	 */
	public function add( int $actorUserId, int $sessionId, int $sourceId, array $form, RequestContextDTO $ctx, string $requestKey, bool $confirmedNotDuplicate ): array {
		$session = $this->sessions->find( $sessionId );
		$event   = null !== $session ? $this->events->find( $session->eventId ) : null;
		if ( null === $session || null === $event || ! $this->guard->canManageEventGuests( $actorUserId, $event ) ) {
			throw new CodedException( ErrorCode::ExamAccess, 'Сеанс не найден.' );
		}
		if ( $session->plannedEndAt <= $this->time->nowUtc() ) {
			throw new CodedException( ErrorCode::ExamClosed, 'Сеанс уже закончился: добавить участника нельзя.' );
		}

		$source = $this->sources->find( $sourceId );
		if ( null === $source || $source->eventId !== $event->id ) {
			throw new CodedException( ErrorCode::ExamConflict, 'Выберите источник заявки.' );
		}

		$form['session_id'] = $session->id;

		if ( ! $confirmedNotDuplicate ) {
			$candidates = $this->candidates( $event->id, $form );
			if ( array() !== $candidates ) {
				return array( 'status' => 'needs_confirmation', 'candidates' => $candidates );
			}
		}

		$application = $this->applications->apply( $source, $form, $ctx, $requestKey, $actorUserId );

		return array( 'status' => 'created' ) + $this->payLink( $actorUserId, $application );
	}

	/**
	 * Ссылка на оплату действующей заявки. Повторный вызов перевыпускает ключ: прежняя ссылка перестаёт работать.
	 *
	 * @return array{application_id: int, pay_url: string, hold_expires_at: string, seconds_left: int}
	 *
	 * @throws CodedException
	 */
	public function reissuePayLink( int $actorUserId, int $applicationId ): array {
		$application = $this->applicationRepo->find( $applicationId );
		$event       = null !== $application ? $this->events->find( $application->eventId ) : null;
		if ( null === $application || null === $event || ! $this->guard->canManageEventGuests( $actorUserId, $event ) ) {
			throw new CodedException( ErrorCode::ExamAccess, 'Заявка не найдена.' );
		}

		return $this->payLink( $actorUserId, $application );
	}

	/** @return array{application_id: int, pay_url: string, hold_expires_at: string, seconds_left: int} */
	private function payLink( int $actorUserId, ExamGuestApplicationDTO $application ): array {
		if ( ! $application->isHeld || null === $application->holdExpiresAt || $application->holdExpiresAt <= $this->time->nowUtc() ) {
			throw new CodedException( ErrorCode::ExamClosed, 'Бронь не действует: оформите заявку заново.' );
		}

		$plain = $this->tokens->issue( ExamTokenPurpose::Payment, $application->id, $actorUserId, $application->holdExpiresAt );

		return array(
			'application_id'  => $application->id,
			'pay_url'         => (string) add_query_arg( array( 'pay' => $plain ), PageRoutes::ExamSignup->url() ),
			'hold_expires_at' => $this->time->toLocal( $application->holdExpiresAt ),
			'seconds_left'    => $this->time->secondsUntil( $this->time->nowUtc(), $application->holdExpiresAt ),
		);
	}

	/**
	 * Кандидаты на дубль по хешам введённых ФИО и телефона — подсказка сотруднику, а не отказ.
	 *
	 * @param array<string, mixed> $form
	 *
	 * @return list<array{name: string, participation_id: int, match: string}>
	 */
	private function candidates( int $eventId, array $form ): array {
		$last  = trim( (string) ( $form['last_name'] ?? '' ) );
		$first = trim( (string) ( $form['first_name'] ?? '' ) );
		$phone = trim( (string) ( $form['phone'] ?? '' ) );
		if ( '' === $last || '' === $first || '' === $phone ) {
			return array(); // форма неполная — её отвергнет проверка полей, дубль искать не по чему
		}

		$hashes = $this->applications->hashesOf( $last, $first, (string) ( $form['middle_name'] ?? '' ), $phone );
		$found  = array();
		foreach ( $this->applications->duplicateCandidatesByHashes( $eventId, $hashes['name'], $hashes['phone'] ) as $candidate ) {
			$participant = $this->participants->find( $candidate['participant_id'] );
			$found[]     = array(
				'name'             => null !== $participant ? $this->materializer->decryptName( $participant ) : '',
				'participation_id' => $candidate['participation_id'],
				'match'            => $candidate['match'],
			);
		}

		return $found;
	}
}
