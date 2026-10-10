<?php

declare( strict_types=1 );

namespace Inc\Services\Exam;

use Inc\DTO\Exam\AttemptContext;
use Inc\DTO\Exam\ExamAccessTokenDTO;
use Inc\DTO\Exam\ExamGuestSessionDTO;
use Inc\Enums\Exam\ExamAudience;
use Inc\Enums\Exam\ExamRegistrationStatus;
use Inc\Enums\Exam\ExamTokenPurpose;
use Inc\Repositories\WPDBRepositories\ExamGuestSessionRepository;
use Inc\Repositories\WPDBRepositories\ExamParticipationRepository;
use Inc\Repositories\WPDBRepositories\ExamRegistrationRepository;
use Inc\Services\Security\PiiCryptoService;

/**
 * Гостевые сессии по куке: приглашение (форма), вход на экзамен, просмотр результата (этап 11a.2, 11b.1).
 *
 * Значение куки — 256 бит случайных данных, в базе только хеш. Сессия действительна, если она не отозвана, не истекла и её
 * поколение равно поколению действующего ключа цели: перевыпуск или отзыв ссылки обрывает уже открытые страницы после обновления.
 * Гость **не становится пользователем WordPress** — личность гостя здесь и только здесь.
 */
class GuestSessionService {

	public const COOKIE_INVITATION = 'fs_exam_inv';
	public const COOKIE_ENTRY      = 'fs_exam_guest';
	public const COOKIE_RESULT     = 'fs_exam_result';
	public const COOKIE_REPORT     = 'fs_exam_report';

	public const SCOPE_INVITATION = 'invitation';
	public const SCOPE_ENTRY      = 'entry';
	public const SCOPE_RESULT     = 'result';
	public const SCOPE_REPORT     = 'report';

	/** Срок строки сессии приглашения, ч: форму открывают заранее, а отправляют позже (токен формы живёт 4 часа). */
	private const INVITATION_HOURS = 12;

	/** Сколько после личного дедлайна сессия ещё открывает страницу результата, мин. */
	private const RESULT_VIEW_MINUTES = 30;

	public function __construct(
		private readonly ExamGuestSessionRepository $sessions,
		private readonly ExamAccessTokenService $tokens,
		private readonly PiiCryptoService $crypto,
		private readonly ExamTime $time,
		private readonly ExamParticipationRepository $participations,
		private readonly ExamRegistrationRepository $registrations,
	) {}

	/**
	 * Открывает сессию приглашения источника.
	 *
	 * @return string Значение куки (в базе — только хеш).
	 */
	public function openInvitation( int $sourceId, int $generation, ?string $expiresAtUtc = null ): string {
		$now = $this->time->nowUtc();

		return $this->open( array(
			'scope'      => self::SCOPE_INVITATION,
			'source_id'  => $sourceId,
			'generation' => $generation,
			'issued_at'  => $now,
			'expires_at' => $expiresAtUtc ?? $this->time->addMinutes( $now, self::INVITATION_HOURS * 60 ),
		) );
	}

	/**
	 * ID источника по куке приглашения; null — нет куки, сессия отозвана, истекла или перевыпущена ссылка.
	 */
	public function resolveInvitation( string $cookie ): ?int {
		$session = $this->find( $cookie, self::SCOPE_INVITATION );
		if ( null === $session || null === $session->sourceId ) {
			return null;
		}

		return $session->generation === $this->tokens->currentGeneration( ExamTokenPurpose::Invitation, $session->sourceId ) && $session->generation > 0
			? $session->sourceId
			: null;
	}

	/** Отзывает все сессии приглашения источника — при перевыпуске и отзыве ссылки. */
	public function revokeBySource( int $sourceId ): void {
		$this->sessions->revokeBySource( $sourceId, $this->time->nowUtc() );
	}

	/**
	 * Открывает сессию входа участия. Предыдущие сессии входа этого участия отзываются (последний вход побеждает: два устройства не пишут одну попытку).
	 *
	 * @return string Значение куки (в базе — только хеш).
	 */
	public function openEntry( ExamAccessTokenDTO $token, int $registrationId, string $expiresAtUtc ): string {
		$now = $this->time->nowUtc();
		$this->sessions->revokeByParticipation( $token->targetId, self::SCOPE_ENTRY, $now );

		return $this->open( array(
			'scope'            => self::SCOPE_ENTRY,
			'participation_id' => $token->targetId,
			'registration_id'  => $registrationId,
			'generation'       => $token->generation,
			'issued_at'        => $now,
			'expires_at'       => $expiresAtUtc,
		) );
	}

	/**
	 * Контекст гостя по куке входа (или по переданной куке); null — сессии нет, она отозвана, истекла или запись не действует.
	 *
	 * Поколение ключа обязано совпасть с действующим, **кроме** случая, когда по участию уже идёт или сдана попытка: перевыпуск ссылки не обрывает
	 * идущий экзамен на том же устройстве и не прячет результат. Новую попытку со старым поколением начать нельзя — попытка одна.
	 */
	public function current( ?string $cookie = null ): ?AttemptContext {
		$cookie ??= isset( $_COOKIE[ self::COOKIE_ENTRY ] ) ? strtolower( sanitize_text_field( wp_unslash( (string) $_COOKIE[ self::COOKIE_ENTRY ] ) ) ) : '';
		$session = $this->find( $cookie, self::SCOPE_ENTRY );
		if ( null === $session || null === $session->participationId || null === $session->registrationId ) {
			return null;
		}

		$participation = $this->participations->find( $session->participationId );
		$registration  = $this->registrations->find( $session->registrationId );
		if ( null === $participation || null === $registration || ExamAudience::Guest->value !== $participation->audience || $registration->participationId !== $participation->id ) {
			return null;
		}

		$ownAttempt = null !== $participation->currentAttemptId;
		if ( ! $ownAttempt ) {
			// До старта нужны действующая запись и свежее поколение ключа.
			$fresh = $session->generation === $this->tokens->currentGeneration( ExamTokenPurpose::Entry, $participation->id ) && $session->generation > 0;
			if ( ! $fresh || $participation->activeRegistrationId !== $registration->id || ExamRegistrationStatus::Confirmed->value !== $registration->status ) {
				return null;
			}
		}

		return new AttemptContext( ExamAudience::Guest, $participation->id, $registration->id, null, null );
	}

	/**
	 * Открывает сессию результата: личная ссылка результата даёт только просмотр — начать, продолжить или пересдать по ней нельзя
	 * ({@see current()} принимает лишь сессии входа). Предыдущие сессии результата этого участия отзываются.
	 *
	 * @return string Значение куки (в базе — только хеш).
	 */
	public function openResult( ExamAccessTokenDTO $token, string $expiresAtUtc ): string {
		$now = $this->time->nowUtc();
		$this->sessions->revokeByParticipation( $token->targetId, self::SCOPE_RESULT, $now );

		return $this->open( array(
			'scope'            => self::SCOPE_RESULT,
			'participation_id' => $token->targetId,
			'generation'       => $token->generation,
			'issued_at'        => $now,
			'expires_at'       => $expiresAtUtc,
		) );
	}

	/**
	 * Участие, результат которого можно показать этой сессии: свой вход (в т.ч. после сдачи) либо действующая сессия результата.
	 * Проверка выполняется при каждом запросе: отозванная сессия не работает, даже если браузер восстановил вкладки и сессионную куку.
	 */
	public function viewableParticipationId(): ?int {
		$entry = $this->current();
		if ( null !== $entry ) {
			return $entry->participationId;
		}

		$cookie  = isset( $_COOKIE[ self::COOKIE_RESULT ] ) ? strtolower( sanitize_text_field( wp_unslash( (string) $_COOKIE[ self::COOKIE_RESULT ] ) ) ) : '';
		$session = $this->find( $cookie, self::SCOPE_RESULT );
		if ( null === $session || null === $session->participationId ) {
			return null;
		}

		$generation    = $this->tokens->currentGeneration( ExamTokenPurpose::Result, $session->participationId );
		$participation = $this->participations->find( $session->participationId );
		if ( $generation <= 0 || $session->generation !== $generation || null === $participation || ExamAudience::Guest->value !== $participation->audience ) {
			return null;
		}

		return $participation->id;
	}

	/**
	 * Открывает сессию школьного отчёта (этап 12.3): школьный преподаватель — не пользователь WordPress, личность — кука отчёта.
	 * Сессия отчёта не даёт ни попытки, ни результата участника: её принимает только страница отчёта.
	 *
	 * @return string Значение куки (в базе — только хеш).
	 */
	public function openReport( ExamAccessTokenDTO $token, string $expiresAtUtc ): string {
		return $this->open( array(
			'scope'      => self::SCOPE_REPORT,
			'report_id'  => $token->targetId,
			'generation' => $token->generation,
			'issued_at'  => $this->time->nowUtc(),
			'expires_at' => $expiresAtUtc,
		) );
	}

	/**
	 * Отчёт, открытый этой сессией; null — нет куки, сессия истекла или ключ отчёта отозван/перевыпущен (поколение не совпало).
	 * Проверка — на каждом запросе страницы.
	 */
	public function currentReportId( ?string $cookie = null ): ?int {
		$cookie ??= isset( $_COOKIE[ self::COOKIE_REPORT ] ) ? strtolower( sanitize_text_field( wp_unslash( (string) $_COOKIE[ self::COOKIE_REPORT ] ) ) ) : '';
		$session = $this->find( $cookie, self::SCOPE_REPORT );
		if ( null === $session || null === $session->reportId ) {
			return null;
		}

		$generation = $this->tokens->currentGeneration( ExamTokenPurpose::Report, $session->reportId );

		return $generation > 0 && $session->generation === $generation ? $session->reportId : null;
	}

	/** После сдачи просмотр результата живёт 30 минут: следующий человек за общим компьютером его не увидит. */
	public function closeAfterSubmit( int $participationId ): void {
		$this->sessions->capByParticipation( $participationId, self::SCOPE_ENTRY, $this->time->addMinutes( $this->time->nowUtc(), self::RESULT_VIEW_MINUTES ) );
	}

	/** Выход: сессия по куке отзывается (кнопка «Завершить сеанс»). */
	public function revoke( string $cookie ): void {
		$session = $this->find( strtolower( $cookie ), self::SCOPE_ENTRY ) ?? $this->find( strtolower( $cookie ), self::SCOPE_RESULT );
		if ( null !== $session ) {
			$this->sessions->update( $session->id, array( 'revoked_at' => $this->time->nowUtc() ) );
		}
	}

	/** Отзывает все сессии входа и результата участия — отмена записи, отзыв ссылки сотрудником. */
	public function revokeByParticipation( int $participationId ): void {
		$now = $this->time->nowUtc();
		$this->sessions->revokeByParticipation( $participationId, self::SCOPE_ENTRY, $now );
		$this->sessions->revokeByParticipation( $participationId, self::SCOPE_RESULT, $now );
	}

	/** После старта попытки сессия живёт до личного дедлайна + время на просмотр результата. Вызывается из старта и продления. */
	public function extendForAttempt( int $participationId, string $deadlineUtc ): void {
		$this->sessions->extendByParticipation( $participationId, self::SCOPE_ENTRY, $this->time->addMinutes( $deadlineUtc, self::RESULT_VIEW_MINUTES ) );
	}

	/**
	 * Сессия по куке и назначению: действующая, не истёкшая. Поколение здесь не сверяется — его проверяет вызывающий для своей цели.
	 */
	public function find( string $cookie, string $scope ): ?ExamGuestSessionDTO {
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $cookie ) ) {
			return null;
		}

		$session = $this->sessions->findByCookieHash( $this->crypto->hash( $cookie ) );
		if ( null === $session || $session->scope !== $scope || $session->expiresAt <= $this->time->nowUtc() ) {
			return null;
		}

		return $session;
	}

	/**
	 * Создаёт строку сессии и возвращает значение куки.
	 *
	 * @param array<string, scalar|null> $row Поля строки без `cookie_hash`.
	 */
	protected function open( array $row ): string {
		$cookie = bin2hex( random_bytes( 32 ) );
		$id     = $this->sessions->insert( $row + array( 'cookie_hash' => $this->crypto->hash( $cookie ) ) );
		if ( 0 === $id ) {
			throw new \RuntimeException( 'Не удалось открыть гостевую сессию.' );
		}

		return $cookie;
	}
}
