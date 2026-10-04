<?php

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
	 */
	public function issue( ExamTokenPurpose $purpose, int $targetId, int $issuerUserId, ?string $expiresAtUtc = null ): string {
		$now = $this->time->nowUtc();

		$this->tokens->revokeByTarget( $purpose, $targetId, $now );

		$plain      = bin2hex( random_bytes( 32 ) );
		$generation = $this->tokens->maxGeneration( $purpose, $targetId ) + 1;

		$id = $this->tokens->insert( array(
			'purpose'        => $purpose->value,
			'target_id'      => $targetId,
			'token_hash'     => $this->crypto->hash( $plain ),
			'generation'     => $generation,
			'expires_at'     => $expiresAtUtc,
			'issuer_user_id' => $issuerUserId,
			'created_at'     => $now,
		) );
		if ( 0 === $id ) {
			throw new \RuntimeException( 'Не удалось сохранить ключ доступа.' );
		}

		return $plain;
	}

	/**
	 * Меняет открытый ключ на запись о нём. Любой отказ — `null`, без различения причин:
	 * неверный формат (до обращения к базе), ключ не найден, другое назначение, отозван, истёк.
	 */
	public function exchange( ExamTokenPurpose $purpose, string $plain ): ?ExamAccessTokenDTO {
		if ( 1 !== preg_match( self::KEY_PATTERN, $plain ) ) {
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

	/** Отзывает действующие ключи цели без выпуска нового. @return int Число отозванных. */
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

	/** Ручная отметка «Ссылка передана»: копирование ссылки её не ставит. */
	public function markPassed( int $tokenId, int $actorUserId ): void {
		$this->tokens->markPassed( $tokenId, $actorUserId, $this->time->nowUtc() );
	}
}
