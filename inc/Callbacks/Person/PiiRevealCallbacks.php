<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Person;

use Inc\Core\BaseController;
use Inc\Enums\Capability;
use Inc\Enums\Nonce;
use Inc\Services\Person\PersonReader;
use Inc\Services\Security\RateLimitService;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class PiiRevealCallbacks
 *
 * AJAX-коллбеки для временного раскрытия (reveal) персональных данных (PII) в административной панели.
 *
 * @package Inc\Callbacks\Person
 *
 * ### Основные обязанности:
 *
 * 1. **Раскрытие одного PII-поля** — временное (на 30 секунд) отображение зашифрованных данных.
 * 2. **Раскрытие всех PII-полей** — массовое раскрытие данных для администратора.
 *
 * ### Архитектурная роль:
 *
 * Делегирует бизнес-логику PersonReader для безопасного чтения PII с логированием доступа.
 * Использует RateLimitService для ограничения частоты запросов (защита от перебора).
 * Требует права Capability::ViewPII для всех операций.
 */
class PiiRevealCallbacks extends BaseController {

	use Authorizer;  // Трейт с методами authorize(), success(), error()
	use Sanitizer;   // Трейт с методами sanitizeInt(), sanitizeText()

	/**
	 * Конструктор коллбеков.
	 *
	 * @param PersonReader     $personReader     Сервис безопасного чтения PII
	 * @param RateLimitService $rateLimitService Сервис ограничения запросов
	 */
	public function __construct(
		private readonly PersonReader     $personReader,
		private readonly RateLimitService $rateLimitService,
	) {
		parent::__construct();
	}

	/**
	 * Раскрывает одно PII-поле (например, паспорт, ИНН, адрес, телефон).
	 * Данные отображаются временно (30 секунд), после чего снова маскируются.
	 *
	 * @return void
	 */
	public function ajaxRevealPiiField(): void {
		$this->authorize( Nonce::RevealPii, Capability::ViewPII );

		// Проверка лимита раскрытий на пользователя
		if ( ! $this->rateLimitService->allowPiiReveal( get_current_user_id() ) ) {
			$this->error( 'Лимит раскрытий превышен.', 429 );
		}

		$personId = $this->sanitizeInt( 'person_id' );
		$field    = $this->sanitizeText( 'field' );
		$reason   = $this->sanitizeText( 'reason' );

		try {
			// readField() — расшифровка одного поля с логированием доступа
			$value = $this->personReader->readField( $personId, $field, $reason );
			$this->success( array( 'value' => $value ) );
		} catch ( \RuntimeException $e ) {
			$this->error( $e->getMessage() );
		}
	}

	/**
	 * Раскрывает все PII-поля лица сразу (паспорт, ИНН, адрес, телефон).
	 * Используется для административного доступа (например, при проверке документов).
	 *
	 * @return void
	 */
	public function ajaxRevealAllPersonPii(): void {
		$this->authorize( Nonce::RevealPii, Capability::ViewPII );

		if ( ! $this->rateLimitService->allowPiiReveal( get_current_user_id() ) ) {
			$this->error( 'Лимит раскрытий превышен.', 429 );
		}

		$personId = $this->sanitizeInt( 'person_id' );
		$reason   = $this->sanitizeText( 'reason' ) ?: 'admin_full_reveal';

		try {
			// readForDisplay() — расшифровка набора полей с логированием
			$dto = $this->personReader->readForDisplay(
				$personId,
				array( 'doc_number', 'inn', 'address', 'phone' ),
				$reason
			);

			$payload = array(
				'doc_number' => $dto->pass,
				'inn'        => $dto->inn,
				'address'    => $dto->address,
				'phone'      => $dto->phone,
			);

			// Получение данных о выдаче документа (кем и когда выдан)
			$issuedParts = $this->personReader->readDocIssuedParts( $personId, $reason );
			$payload['doc_issued_by']   = $issuedParts['by'];
			$payload['doc_issued_date'] = $issuedParts['date'];

			$this->success( $payload );
		} catch ( \RuntimeException $e ) {
			$this->error( $e->getMessage() );
		}
	}
}