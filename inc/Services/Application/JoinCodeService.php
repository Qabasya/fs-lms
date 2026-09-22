<?php

declare( strict_types=1 );

namespace Inc\Services\Application;

use Inc\Services\Security\PiiCryptoService;

/**
 * Class JoinCodeService
 *
 * Генерация и валидация JOIN-кодов для формы родителя.
 *
 * @package Inc\Services
 *
 * ### Основные обязанности:
 *
 * 1. **Генерация кода** — криптографически случайный код формата JOIN-XXXX-XXXX-XXXX.
 * 2. **Хэширование** — делегирует в PiiCryptoService::hash() для searchable-хранения.
 * 3. **Валидация формата** — проверяет входящие коды перед обработкой.
 * 4. **Сроки** — срок выданной ссылки (72 ч) и срок самой заявки (20 дней).
 *
 * ### Архитектурная роль:
 *
 * В БД хранится только хэш кода (join_code_hash), сам код передаётся пользователю
 * в JOIN-ссылке и никогда не сохраняется. Это исключает раскрытие кода при утечке БД.
 *
 * ### Алфавит:
 *
 * ABCDEFGHJKLMNPQRSTUVWXYZ23456789 — без визуально похожих символов (0/O, 1/I/l).
 * 12 значащих символов × log2(32) = ~60 бит энтропии.
 */
readonly class JoinCodeService {

	/** Срок жизни выданной ссылки, часы (см. {@see self::expiresAt()}). */
	public const TTL_HOURS = 72;

	/** Срок жизни заявки, дни (см. {@see self::applicationExpiresAt()}). */
	public const APPLICATION_TTL_DAYS = 20;

	private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
	private const FORMAT_REGEX = '/^JOIN-[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}$/';
	private const SEGMENT_LENGTH = 4;
	private const SEGMENT_COUNT  = 3;

	/**
	 * Конструктор сервиса.
	 *
	 * @param PiiCryptoService $crypto Сервис шифрования для делегирования hash()
	 */
	public function __construct(
		private PiiCryptoService $crypto,
	) {}

	/**
	 * Генерирует JOIN-код формата JOIN-XXXX-XXXX-XXXX.
	 *
	 * Каждый символ выбирается через random_int() из алфавита без
	 * визуально похожих символов (0/O, 1/I/l).
	 *
	 * @return string Код вида JOIN-ABCD-EF23-GHKL
	 */
	public function generate(): string {
		$alphabetLength = strlen( self::ALPHABET );
		$segments       = array();

		for ( $s = 0; $s < self::SEGMENT_COUNT; $s++ ) {
			$segment = '';
			for ( $i = 0; $i < self::SEGMENT_LENGTH; $i++ ) {
				$segment .= self::ALPHABET[ random_int( 0, $alphabetLength - 1 ) ];
			}
			$segments[] = $segment;
		}

		return 'JOIN-' . implode( '-', $segments );
	}

	/**
	 * Возвращает детерминированный хэш кода для хранения в БД.
	 *
	 * Делегирует в PiiCryptoService::hash(), который нормализует
	 * входную строку и применяет SHA-256 с солью.
	 *
	 * @param string $code JOIN-код (JOIN-XXXX-XXXX-XXXX)
	 *
	 * @return string SHA-256 хэш (64 hex-символа)
	 */
	public function hash( string $code ): string {
		return $this->crypto->hash( $code );
	}

	/**
	 * Проверяет соответствие строки формату JOIN-кода.
	 *
	 * Используется в callbacks перед обработкой пользовательского ввода,
	 * чтобы отсеять невалидный формат до обращения к БД.
	 *
	 * @param string $code Входная строка для проверки
	 *
	 * @return bool true если формат корректен
	 */
	public function isValidFormat( string $code ): bool {
		return (bool) preg_match( self::FORMAT_REGEX, $code );
	}

	/**
	 * Срок жизни выданной родителю ссылки: 72 часа с момента, когда её
	 * скопировали (копирование в таблице заявок сбрасывает счётчик заново —
	 * {@see \Inc\Services\Application\ApplicationService::refreshJoinExpiry()}).
	 *
	 * Ссылка не живёт дольше самой заявки. Истёкшая ссылка заявку не закрывает:
	 * срок заявки ({@see self::applicationExpiresAt()}) идёт отдельно, и ссылку
	 * можно выдать заново.
	 *
	 * @param string|null $applicationExpiresAt Срок заявки (UTC) — потолок срока ссылки
	 *
	 * @return string 'Y-m-d H:i:s' в UTC — сравнение сроков в репозитории тоже идёт по UTC.
	 */
	public function expiresAt( ?string $applicationExpiresAt = null ): string {
		$expiresAt = gmdate( 'Y-m-d H:i:s', time() + self::TTL_HOURS * HOUR_IN_SECONDS );

		if ( null !== $applicationExpiresAt && '' !== $applicationExpiresAt && $applicationExpiresAt < $expiresAt ) {
			return $applicationExpiresAt;
		}

		return $expiresAt;
	}

	/**
	 * Срок жизни заявки: 20 дней с подачи (или с восстановления из корзины/архива).
	 * Пока он не вышел, заявка ждёт родителя и держит временный доступ ученика.
	 *
	 * @return string 'Y-m-d H:i:s' в UTC
	 */
	public function applicationExpiresAt(): string {
		return gmdate( 'Y-m-d H:i:s', time() + self::APPLICATION_TTL_DAYS * DAY_IN_SECONDS );
	}

	/**
	 * Истёк ли срок (выданной ссылки или заявки).
	 *
	 * Проверяется в момент использования ссылки, а не только cron-ом
	 * `ExpireApplications`: между тиками просроченная ссылка иначе продолжала
	 * бы работать. Сравнение — в UTC, как и запись в `join_code_expires_at`.
	 *
	 * @param string|null $expiresAt Срок из БД ('Y-m-d H:i:s', UTC) или null
	 *
	 * @return bool true, если срок задан и уже прошёл
	 */
	public function isExpired( ?string $expiresAt ): bool {
		if ( null === $expiresAt || '' === $expiresAt ) {
			return false;
		}

		return $expiresAt < gmdate( 'Y-m-d H:i:s' );
	}
}