<?php
/**
 * Хранение общей учётки промо и её связей.
 *
 * @package Inc\Repositories\WPDBRepositories\Promo
 */

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories\Promo;

use Inc\Enums\Settings\TableName;
use Inc\Repositories\WPDBRepositories\AbstractExamRepository;

/** Запись общей модели; связь с источником задаётся вызывающим модулем. */
class PromoAccountRepository extends AbstractExamRepository {

	/**
	 * Создаёт промо-аккаунт с отдельным сроком в UTC.
	 *
	 * @throws \InvalidArgumentException При неверных идентификаторах или типе источника.
	 */
	public function createAccount( int $userId, int $personId, string $originType, string $expiresAtUtc, string $nowUtc ): int {
		$this->assertIdentity( $userId, $originType );
		if ( $personId <= 0 ) {
			throw new \InvalidArgumentException( 'Некорректный идентификатор физлица.' );
		}
		return $this->insertRow(
			TableName::PromoAccounts->prefixed(),
			array(
				'user_id'     => $userId,
				'person_id'   => $personId,
				'origin_type' => $originType,
				'expires_at'  => $expiresAtUtc,
				'created_at'  => $nowUtc,
				'updated_at'  => $nowUtc,
			)
		);
	}

	/**
	 * Создаёт отдельное событие с уникальной ссылкой на источник.
	 *
	 * @throws \InvalidArgumentException При неверном идентификаторе или типе источника.
	 */
	public function createEvent( string $sourceType, int $sourceId, string $nowUtc ): int {
		$this->assertIdentity( $sourceId, $sourceType );
		return $this->insertRow(
			TableName::PromoEvents->prefixed(),
			array(
				'source_type' => $sourceType,
				'source_id'   => $sourceId,
				'created_at'  => $nowUtc,
				'updated_at'  => $nowUtc,
			)
		);
	}

	/**
	 * Связывает конкретное участие с учёткой; повтор и коллизии распознаются по уникальным ключам БД.
	 *
	 * @throws \InvalidArgumentException При неверных идентификаторах.
	 */
	public function createLink( int $accountId, int $eventId, int $participantId, string $expiresAtUtc, string $nowUtc ): int {
		if ( $accountId <= 0 || $eventId <= 0 || $participantId <= 0 ) {
			throw new \InvalidArgumentException( 'Некорректная связь промо-аккаунта.' );
		}
		return $this->insertRow(
			TableName::PromoEventLinks->prefixed(),
			array(
				'account_id'     => $accountId,
				'event_id'       => $eventId,
				'participant_id' => $participantId,
				'expires_at'     => $expiresAtUtc,
				'created_at'     => $nowUtc,
				'updated_at'     => $nowUtc,
			)
		);
	}

	/**
	 * Проверяет идентификатор и тип источника.
	 *
	 * @throws \InvalidArgumentException Если идентификатор или тип некорректен.
	 */
	private function assertIdentity( int $id, string $type ): void {
		if ( $id <= 0 || 1 !== preg_match( '/\A[a-z][a-z0-9_]{0,31}\z/', $type ) ) {
			throw new \InvalidArgumentException( 'Некорректный источник промо-аккаунта.' );
		}
	}
}
