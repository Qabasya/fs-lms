<?php

declare( strict_types=1 );

namespace Inc\Services\Exam;

use Inc\DTO\Exam\ExamGuestApplicationDTO;
use Inc\DTO\Exam\ExamSourceDTO;
use Inc\DTO\RequestContextDTO;
use Inc\Enums\Access\UserRole;
use Inc\Enums\Exam\ExamOutboxEvent;
use Inc\Enums\Log\ErrorCode;
use Inc\Repositories\OptionsRepositories\UserRepository;
use Inc\Repositories\WPDBRepositories\ExamEventRepository;
use Inc\Repositories\WPDBRepositories\ExamGuestApplicationRepository;
use Inc\Repositories\WPDBRepositories\ExamParticipantRepository;
use Inc\Repositories\WPDBRepositories\ExamParticipationRepository;
use Inc\Repositories\WPDBRepositories\ExamSessionRepository;
use Inc\Services\Person\ConsentService;
use Inc\Services\Security\PiiCryptoService;
use Inc\Services\Security\RateLimitService;
use Inc\Services\Shared\PluginConfig;
use Inc\Shared\CodedException;
use Inc\Shared\GuestFormException;

/**
 * Заявка гостя на экзамен: проверка формы, лимиты, согласия, временная бронь (этап 11a.1).
 *
 * **Гость — не `Person`, не ученик и не пользователь WordPress.** До оплаты участника нет: заявка хранит зашифрованный черновик формы
 * ({@see GuestParticipantMaterializer} создаёт участника при подтверждении). Школа, класс и преподаватель берутся **из источника**
 * (снимок в заявке), а не из формы: то, что прислал браузер, игнорируется. Личность = ФИО и телефон вместе ({@see GuestIdentity});
 * вторая активная заявка той же личности на проведение — конфликт без подробностей (чужих данных в ответе нет).
 *
 * Лимиты: бронь с IP — не больше `examIpHourlyLimit()` в час и `examIpActiveHoldsLimit()` одновременных; по источнику —
 * `examSourceActiveHoldsLimit()` одновременных (превышение пишет `SourceLimitExceeded`). Лимиты по IP к заявке сотрудника не применяются.
 */
class GuestApplicationService {

	public const CONSENT_PD       = 'pd_processing';
	public const CONSENT_TRANSFER = 'pd_transfer';
	public const CONSENT_MARKETING = 'marketing';

	/** Согласия, которые форма вправе передать; любые другие ключи отбрасываются. */
	private const ALLOWED_CONSENTS = array( self::CONSENT_PD, self::CONSENT_TRANSFER, self::CONSENT_MARKETING );

	private const MAX_NAME_LENGTH      = 100;
	private const MAX_MESSENGER_LENGTH = 100;
	private const PHONE_DIGITS         = 11;

	public function __construct(
		private readonly ExamGuestApplicationRepository $applications,
		private readonly ExamParticipantRepository $participants,
		private readonly ExamParticipationRepository $participations,
		private readonly ExamEventRepository $events,
		private readonly ExamSessionRepository $sessions,
		private readonly ExamHoldService $holds,
		private readonly GuestIdentity $identity,
		private readonly PiiCryptoService $crypto,
		private readonly ConsentService $consents,
		private readonly PluginConfig $config,
		private readonly ExamOutbox $outbox,
		private readonly RateLimitService $rateLimit,
		private readonly UserRepository $users,
		private readonly GuestParticipantMaterializer $materializer,
	) {}

	/**
	 * @param array<string, mixed> $form `last_name`, `first_name`, `middle_name?`, `phone`, `messenger?`, `session_id`, `consents` (список ключей)
	 *
	 * @throws GuestFormException Ошибка поля формы или вошедший ученик/родитель (`field = cabinet`).
	 * @throws CodedException     Лимит, закрытая запись, конфликт.
	 */
	public function apply( ExamSourceDTO $source, array $form, RequestContextDTO $ctx, string $requestKey, ?int $staffUserId = null ): ExamGuestApplicationDTO {
		if ( null === $staffUserId ) {
			$this->assertNotCenterMember( $ctx->actorUserId );
		}

		$fields = $this->validate( $form );

		$event = $this->events->find( $source->eventId );
		if ( ! $source->isActive || null !== $source->keyRevokedAt || null === $event || 'published' !== $event->status || ! $event->guestRegistrationEnabled ) {
			throw new CodedException( ErrorCode::ExamClosed, 'Запись закрыта.' );
		}

		$session = $this->sessions->find( $fields['session_id'] );
		if ( null === $session || $session->eventId !== $event->id ) {
			throw new GuestFormException( ErrorCode::ExamConflict, 'Выберите дату и время.', 'session_id' );
		}

		$ipHash = '' !== $ctx->ip ? $this->rateLimit->ipHash( $ctx->ip ) : '';
		$this->assertLimits( $source, $event->id, $ctx->ip, $ipHash, null !== $staffUserId );

		$consentRefs = $this->recordConsents( $fields['consents'], $ctx );
		$data        = array(
			'event_id'        => $event->id,
			'session_id'      => $session->id,
			'source_id'       => $source->id,
			'identity_hash'   => $this->identity->identityHash( $fields['last_name'], $fields['first_name'], $fields['middle_name'], $fields['phone'] ),
			'request_key'     => $requestKey,
			// Из источника, не из формы: школу, класс и преподавателя гость выбрать не может.
			'source_snapshot' => (string) wp_json_encode( array(
				'school_name'  => $source->schoolName,
				'school_key'   => $source->schoolKey,
				'grade'        => $source->grade,
				'teacher_name' => $source->teacherName,
				'label'        => $source->label,
			) ),
			'draft_enc'       => $this->materializer->seal( (string) wp_json_encode( array(
				'last_name'   => $fields['last_name'],
				'first_name'  => $fields['first_name'],
				'middle_name' => $fields['middle_name'],
				'phone'       => $fields['phone'],
				'messenger'   => $fields['messenger'],
			) ) ),
			'consent_refs'    => (string) wp_json_encode( $consentRefs ),
		);
		if ( '' !== $ipHash ) {
			$data['ip_hash'] = $ipHash;
		}
		if ( null !== $staffUserId ) {
			$data['created_by_user_id'] = $staffUserId;
		}

		return $this->holds->capture( $data, $this->config->examHoldMinutes() );
	}

	/** Участник из заявки (вызывает подтверждение оплаты через {@see GuestParticipantMaterializer}). */
	public function materializeParticipant( ExamGuestApplicationDTO $application ): int {
		return $this->materializer->materialize( $application );
	}

	/**
	 * Кандидаты на дубль для сотрудника: участники **того же проведения** с совпавшим хешем ФИО или телефона.
	 * Подсказка, а не объединение и не отказ; совпадение только по телефону — кандидат, не дубль.
	 *
	 * @return list<array{participant_id: int, participation_id: int, match: string}>
	 */
	public function duplicateCandidates( int $eventId, int $participantId ): array {
		$participant = $this->participants->find( $participantId );
		if ( null === $participant ) {
			return array();
		}

		return $this->duplicateCandidatesByHashes( $eventId, (string) $participant->nameHash, (string) $participant->phoneHash, $participantId );
	}

	/**
	 * То же по хешам введённых данных — до создания участника (добавление гостя на месте).
	 *
	 * @return list<array{participant_id: int, participation_id: int, match: string}>
	 */
	public function duplicateCandidatesByHashes( int $eventId, string $nameHash, string $phoneHash, int $excludeParticipantId = 0 ): array {
		$found = array();
		foreach ( $this->participations->findByEvent( $eventId ) as $participation ) {
			if ( $participation->participantId === $excludeParticipantId ) {
				continue;
			}
			$other = $this->participants->find( $participation->participantId );
			if ( null === $other ) {
				continue;
			}

			$sameName  = '' !== $nameHash && $other->nameHash === $nameHash;
			$samePhone = '' !== $phoneHash && $other->phoneHash === $phoneHash;
			if ( $sameName || $samePhone ) {
				$found[] = array(
					'participant_id'   => $other->id,
					'participation_id' => $participation->id,
					'match'            => $sameName && $samePhone ? 'both' : ( $sameName ? 'name' : 'phone' ),
				);
			}
		}

		return $found;
	}

	/** Хеши введённых ФИО и телефона (для проверки дублей до создания заявки). @return array{name: string, phone: string} */
	public function hashesOf( string $last, string $first, string $middle, string $phone ): array {
		return array( 'name' => $this->identity->nameHash( $last, $first, $middle ), 'phone' => $this->identity->phoneHash( $phone ) );
	}

	// ── Проверки ─────────────────────────────────────────────────────────────────────────────────────

	/**
	 * Вошедший ученик или родитель центра на гостевую форму не допускается: записаться он вправе в кабинете, где действует утверждение.
	 *
	 * @throws GuestFormException
	 */
	private function assertNotCenterMember( int $userId ): void {
		if ( $userId <= 0 ) {
			return;
		}

		$role = $this->users->getById( $userId )?->role;
		if ( UserRole::FSStudent === $role || UserRole::FSParent === $role ) {
			throw new GuestFormException( ErrorCode::ExamAccess, 'Вы уже учитесь у нас: запись на экзамен — в личном кабинете.', 'cabinet' );
		}
	}

	/**
	 * Серверная проверка формы — независимо от клиента.
	 *
	 * @param array<string, mixed> $form
	 *
	 * @return array{last_name: string, first_name: string, middle_name: string, phone: string, messenger: string, session_id: int, consents: list<string>}
	 *
	 * @throws GuestFormException
	 */
	private function validate( array $form ): array {
		$last      = trim( (string) ( $form['last_name'] ?? '' ) );
		$first     = trim( (string) ( $form['first_name'] ?? '' ) );
		$middle    = trim( (string) ( $form['middle_name'] ?? '' ) );
		$phone     = trim( (string) ( $form['phone'] ?? '' ) );
		$messenger = trim( (string) ( $form['messenger'] ?? '' ) );
		$sessionId = (int) ( $form['session_id'] ?? 0 );

		if ( '' === $last || mb_strlen( $last ) > self::MAX_NAME_LENGTH ) {
			throw new GuestFormException( ErrorCode::ExamConflict, 'Укажите фамилию (до 100 символов).', 'last_name' );
		}
		if ( '' === $first || mb_strlen( $first ) > self::MAX_NAME_LENGTH ) {
			throw new GuestFormException( ErrorCode::ExamConflict, 'Укажите имя (до 100 символов).', 'first_name' );
		}
		if ( mb_strlen( $middle ) > self::MAX_NAME_LENGTH ) {
			throw new GuestFormException( ErrorCode::ExamConflict, 'Отчество слишком длинное.', 'middle_name' );
		}
		if ( self::PHONE_DIGITS !== strlen( $this->identity->normalizePhone( $phone ) ) ) {
			throw new GuestFormException( ErrorCode::ExamConflict, 'Укажите телефон: 11 цифр.', 'phone' );
		}
		if ( mb_strlen( $messenger ) > self::MAX_MESSENGER_LENGTH ) {
			throw new GuestFormException( ErrorCode::ExamConflict, 'Не больше 100 символов.', 'messenger' );
		}
		if ( $sessionId <= 0 ) {
			throw new GuestFormException( ErrorCode::ExamConflict, 'Выберите дату и время.', 'session_id' );
		}

		$consents = array_values( array_intersect( self::ALLOWED_CONSENTS, array_map( 'strval', (array) ( $form['consents'] ?? array() ) ) ) );
		if ( ! in_array( self::CONSENT_PD, $consents, true ) ) {
			throw new GuestFormException( ErrorCode::ExamConsent, 'Для записи нужно согласие на обработку персональных данных.', 'consent_pd' );
		}

		return array(
			'last_name'   => $last,
			'first_name'  => $first,
			'middle_name' => $middle,
			'phone'       => $phone,
			'messenger'   => $messenger,
			'session_id'  => $sessionId,
			'consents'    => $consents,
		);
	}

	/**
	 * Лимиты брони. IP — не для заявки сотрудника (он оформляет с площадки); источник — для всех.
	 *
	 * @throws CodedException `ExamLimit`
	 */
	private function assertLimits( ExamSourceDTO $source, int $eventId, string $ip, string $ipHash, bool $byStaff ): void {
		if ( ! $byStaff && '' !== $ipHash ) {
			if ( $this->applications->countHeldByIp( $ipHash ) >= $this->config->examIpActiveHoldsLimit() || ! $this->rateLimit->allowExamHoldCreation( $ip, $this->config->examIpHourlyLimit() ) ) {
				throw new CodedException( ErrorCode::ExamLimit, 'Слишком много заявок с этого адреса. Обратитесь к сотруднику.' );
			}
		}

		if ( $this->applications->countHeldBySource( $source->id ) >= $this->config->examSourceActiveHoldsLimit() ) {
			$this->outbox->add( ExamOutboxEvent::SourceLimitExceeded, 'source', $source->id, 1, array( 'event_id' => $eventId, 'source_id' => $source->id ) );

			throw new CodedException( ErrorCode::ExamLimit, 'Слишком много заявок по этой ссылке, обратитесь к сотруднику.' );
		}
	}

	/**
	 * Фиксирует согласия гостя и возвращает ссылки на них (`тип => ID записи согласия`).
	 *
	 * @param list<string> $types
	 *
	 * @return array<string, int>
	 */
	private function recordConsents( array $types, RequestContextDTO $ctx ): array {
		$refs = array();
		foreach ( $types as $type ) {
			$refs[ $type ] = $this->consents->recordSelfConsent( null, $type, $ctx );
			if ( $refs[ $type ] <= 0 ) {
				// Согласие обязано быть записано: без него обработка данных гостя незаконна, заявку не принимаем.
				throw new \RuntimeException( 'Не удалось сохранить согласие на обработку данных.' );
			}
		}

		return $refs;
	}
}
