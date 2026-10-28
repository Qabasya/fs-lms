<?php
/**
 * Секретные ссылки экзаменов: приглашение формы, вход гостя, результат, школьный отчёт, оплата (SPEC §6, §8).
 *
 * @package FS_LMS
 */

declare( strict_types=1 );

namespace Inc\Services\Exam;

use Inc\DTO\Exam\ExamAccessTokenDTO;
use Inc\Enums\Exam\ExamTokenPurpose;
use Inc\Repositories\WPDBRepositories\ExamAccessTokenRepository;
use Inc\Services\Security\PiiCryptoService;

/**
 * Секретные ссылки экзаменов: приглашение формы, вход гостя, результат, школьный отчёт, оплата (SPEC §6, §8).
 *
 * Ключ — 256 бит случайных данных, 64 символа hex в нижнем регистре (`PiiCryptoService::hash()` приводит строку к нижнему
 * регистру, поэтому ключ генерируется сразу в нём). В базе только хеш; открытый ключ существует лишь в ответе `issue()`:
 * его нельзя ни сохранять, ни логировать. Перевыпуск отзывает прежний ключ цели, поколение растёт — по нему гостевые
 * сессии узнают, что вход по старой ссылке больше недействителен.
 *
 * Сервис не открывает транзакций и не знает, чья цель (`target_id`): выпуск = отзыв + выбор поколения + вставка, и
 * вызывающий сервис обязан выполнять его внутри своей транзакции, заблокировав цель (источник, участие) первым оператором,
 * иначе два одновременных выпуска оставят два действующих ключа. Соответствие назначения цели тоже проверяет он:
 * ключ `entry` не открывает `result` и наоборот — за это отвечает параметр `$purpose` в {@see exchange()}.
 *
 * Сроки и формат ссылок приглашений `JoinCodeService` (72 часа, `JOIN-…`) не используются намеренно (SPEC §8).
 */
class ExamAccessTokenService {

	/** Длина открытого ключа: 32 байта = 64 hex-символа. */
	private const KEY_PATTERN = '/^[a-f0-9]{64}$/';

	/**
	 * Короткий ключ ссылки приглашения школы: 6 символов (~30 бит), алфавит как у JOIN-кодов без похожих символов.
	 * Ключ открывает только форму записи, а не данные участника; перебор ограничен общим лимитом неудач по IP (11a.2.5).
	 * Ключи входа, результата и отчёта остаются длинными: за ними персональные данные.
	 */
	private const SHORT_ALPHABET = 'abcdefghjklmnpqrstuvwxyz23456789';
	private const SHORT_LENGTH   = 6;
	private const SHORT_PATTERN  = '/^[a-z2-9]{6}$/';

	/** Получает хранилище хешей и часы; открытый ключ возвращается только вызывающему сервису. */
	public function __construct(
		private readonly ExamAccessTokenRepository $tokens,
		private readonly PiiCryptoService $crypto,
		private readonly ExamTime $time,
	) {}

	/**
	 * Выпускает новый ключ цели; прежние ключи этой цели перестают действовать.
	 *
	 * @param string|null $expiresAtUtc Срок действия (UTC) или null — бессрочно.
	 *
	 * @return string Открытый ключ — единственный раз.
	 *
	 * @throws \RuntimeException Новый ключ доступа не удалось сохранить.
	 */
	public function issue( ExamTokenPurpose $purpose, int $targetId, int $issuerUserId, ?string $expiresAtUtc = null ): string {
		$now = $this->time->nowUtc();

		$this->tokens->revokeByTarget( $purpose, $targetId, $now );

		$plain      = $this->newKey( $purpose );
		$generation = $this->tokens->maxGeneration( $purpose, $targetId ) + 1;

		$id = $this->tokens->insert(
			array(
				'purpose'        => $purpose->value,
				'target_id'      => $targetId,
				'token_hash'     => $this->crypto->hash( $plain ),
				'key_enc'        => $this->sealInvitationKey( $purpose, $plain ),
				'generation'     => $generation,
				'expires_at'     => $expiresAtUtc,
				'issuer_user_id' => $issuerUserId,
				'created_at'     => $now,
			)
		);
		if ( 0 === $id ) {
			throw new \RuntimeException( 'Не удалось сохранить ключ доступа.' );
		}

		return $plain;
	}

	/**
	 * Ключи приглашения школы, школьного отчёта и входа гостя хранятся ещё и зашифрованными: сотрудник должен копировать ссылку,
	 * а не перевыпускать её (перевыпуск отзывает прежнюю). Ключи результата и оплаты зашифрованными не хранятся. Нет ключа шифрования —
	 * ссылку при выпуске отдают как раньше, а в списке её нет.
	 */
	private function sealInvitationKey( ExamTokenPurpose $purpose, string $plain ): ?string {
		if ( ! in_array( $purpose, array( ExamTokenPurpose::Invitation, ExamTokenPurpose::Report, ExamTokenPurpose::Entry ), true ) ) {
			return null;
		}

		try {
			return base64_encode( $this->crypto->encrypt( $plain ) );
		} catch ( \Throwable ) {
			return null;
		}
	}

	/** Открытый ключ действующего приглашения цели; null — ключа нет или он выпущен до хранения (достаточно перевыпустить). */
	public function invitationKey( int $targetId ): ?string {
		return $this->storedKey( ExamTokenPurpose::Invitation, $targetId );
	}

	/** Открытый ключ действующей ссылки входа гостя; null — ключа нет или он выпущен до хранения (достаточно перевыпустить). */
	public function entryKey( int $participationId ): ?string {
		return $this->storedKey( ExamTokenPurpose::Entry, $participationId );
	}

	/** Открытый ключ действующей ссылки школьного отчёта; null — ключа нет или он выпущен до хранения (достаточно выдать заново). */
	public function reportKey( int $reportId ): ?string {
		return $this->storedKey( ExamTokenPurpose::Report, $reportId );
	}

	/** Расшифровывает сохранённый открытый ключ действующей ссылки; null — не сохранён или не читается. */
	private function storedKey( ExamTokenPurpose $purpose, int $targetId ): ?string {
		$sealed = $this->tokens->findActive( $purpose, $targetId )?->keyEnc;
		if ( null === $sealed || '' === $sealed ) {
			return null;
		}

		try {
			$plain = $this->crypto->decrypt( (string) base64_decode( $sealed, true ) );
		} catch ( \Throwable ) {
			return null;
		}

		return '' !== $plain ? $plain : null;
	}

	/** Новый открытый ключ: короткий уникальный для приглашений школ, длинный для остальных назначений. */
	private function newKey( ExamTokenPurpose $purpose ): string {
		if ( ExamTokenPurpose::Invitation !== $purpose ) {
			return bin2hex( random_bytes( 32 ) );
		}

		$max = strlen( self::SHORT_ALPHABET ) - 1;
		do {
			$plain = '';
			for ( $i = 0; $i < self::SHORT_LENGTH; $i++ ) {
				$plain .= self::SHORT_ALPHABET[ random_int( 0, $max ) ];
			}
		} while ( null !== $this->tokens->findByHash( $this->crypto->hash( $plain ) ) );

		return $plain;
	}

	/**
	 * Меняет открытый ключ на запись о нём. Любой отказ — `null`, без различения причин:
	 * неверный формат (до обращения к базе), ключ не найден, другое назначение, отозван, истёк.
	 */
	public function exchange( ExamTokenPurpose $purpose, string $plain ): ?ExamAccessTokenDTO {
		$isShort = ExamTokenPurpose::Invitation === $purpose && 1 === preg_match( self::SHORT_PATTERN, $plain );
		if ( ! $isShort && 1 !== preg_match( self::KEY_PATTERN, $plain ) ) {
			return null;
		}

		$token = $this->tokens->findByHash( $this->crypto->hash( $plain ) );
		if ( null === $token || $token->purpose !== $purpose->value || null !== $token->revokedAt ) {
			return null;
		}
		if ( null !== $token->expiresAt && $token->expiresAt <= $this->time->nowUtc() ) {
			return null;
		}

		return $token;
	}

	/**
	 * Отзывает действующие ключи цели без выпуска нового.
	 *
	 * @return int Число отозванных.
	 */
	public function revoke( ExamTokenPurpose $purpose, int $targetId ): int {
		return $this->tokens->revokeByTarget( $purpose, $targetId, $this->time->nowUtc() );
	}

	/** Поколение действующего ключа (0 — действующего ключа нет). */
	public function currentGeneration( ExamTokenPurpose $purpose, int $targetId ): int {
		return $this->tokens->findActive( $purpose, $targetId )->generation ?? 0;
	}

	/** Есть ли у цели действующий ключ. */
	public function hasActive( ExamTokenPurpose $purpose, int $targetId ): bool {
		return null !== $this->tokens->findActive( $purpose, $targetId );
	}

	/** Действующий ключ цели (запись, не открытый ключ) — для отметки «Ссылка передана». */
	public function activeToken( ExamTokenPurpose $purpose, int $targetId ): ?\Inc\DTO\Exam\ExamAccessTokenDTO {
		return $this->tokens->findActive( $purpose, $targetId );
	}

	/**
	 * Цели из списка, чей действующий ключ отмечен «Ссылка передана».
	 *
	 * @param list<int> $targetIds
	 * @return array<int, true> Ключ — id цели.
	 */
	public function passedTargets( ExamTokenPurpose $purpose, array $targetIds ): array {
		return array_fill_keys( $this->tokens->passedTargetIds( $purpose, $targetIds ), true );
	}

	/** Снимает ручную отметку «Ссылка передана». */
	public function clearPassed( int $tokenId ): void {
		$this->tokens->clearPassed( $tokenId );
	}

	/** Ручная отметка «Ссылка передана»: копирование ссылки её не ставит. */
	public function markPassed( int $tokenId, int $actorUserId ): void {
		$this->tokens->markPassed( $tokenId, $actorUserId, $this->time->nowUtc() );
	}
}
