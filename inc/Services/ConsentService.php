<?php

declare( strict_types=1 );

namespace Inc\Services;

use Inc\Contracts\ClockInterface;
use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Log\Events\ConsentChangedEvent;
use Inc\DTO\RequestContextDTO;
use Inc\Enums\AuditAction;
use Inc\Enums\LogEvent;
use Inc\Repositories\OptionsRepositories\ConsentDefinitionsRepository;
use Inc\Repositories\WPDBRepositories\ConsentRepository;
use RuntimeException;

/**
 * Управление согласиями на обработку ПД (152-ФЗ).
 *
 * Каждое согласие — отдельная WP-страница. Версия = sha256(post_content).
 * История версий хранится в штатных WP-ревизиях.
 */
readonly class ConsentService {

	public function __construct(
		private ConsentRepository            $consentRepository,
		private AuditService                 $auditService,
		private ConsentDefinitionsRepository $consentDefinitions,
		private ClockInterface               $clock,
		private LogEventDispatcherInterface  $logEvents,
	) {}

	/**
	 * Возвращает текущий sha256-хеш текста согласия.
	 *
	 * @throws RuntimeException Если страница не создана или не найдена
	 */
	public function getCurrentVersion( string $typeKey ): string {
		$page = $this->requirePage( $typeKey );
		return hash( 'sha256', $page->post_content );
	}

	/**
	 * Возвращает человекочитаемое название согласия.
	 */
	public function getDefinitionName( string $typeKey ): string {
		$def = $this->consentDefinitions->findByKey( $typeKey );
		return $def ? (string) ( $def['name'] ?? $typeKey ) : $typeKey;
	}

	/**
	 * Возвращает HTML-текст согласия по ключу типа и версии (sha256-хеш).
	 * Если version == sha256(current) — возвращает текущую версию.
	 * Иначе ищет совпадение среди WP-ревизий страницы.
	 *
	 * @throws RuntimeException Если страница или версия не найдена
	 */
	public function getDocumentText( string $typeKey, string $version ): string {
		$page = $this->requirePage( $typeKey );

		if ( hash( 'sha256', $page->post_content ) === $version || 'current' === $version ) {
			return $page->post_content;
		}

		foreach ( wp_get_post_revisions( $page->ID, array( 'order' => 'DESC' ) ) as $revision ) {
			if ( hash( 'sha256', $revision->post_content ) === $version ) {
				return $revision->post_content;
			}
		}

		throw new RuntimeException( "Версия согласия не найдена: {$version}" );
	}

	/**
	 * Фиксирует подписание согласия субъектом (self).
	 *
	 * @throws RuntimeException Если страница согласия не настроена
	 */
	public function recordSelfConsent( ?int $appId, string $typeKey, RequestContextDTO $ctx ): int {
		$version = $this->getCurrentVersion( $typeKey );

		$id = $this->consentRepository->create( array(
			'application_id'       => $appId,
			'consent_type'         => $typeKey,
			'subject_role'         => 'self',
			'version'              => $version,
			'document_hash'        => $version,
			'ip_address'           => $ctx->ip,
			'user_agent'           => $ctx->userAgent,
			'accepted_at'          => $this->clock->now( 'mysql', true ),
			'signed_for_person_id' => null,
		) );

		$this->auditService->recordAnonymous(
			AuditAction::ConsentSigned->value,
			'consent',
			$id,
			array( 'consent_type' => $typeKey, 'version' => $version, 'application_id' => $appId, 'subject_role' => 'self' )
		);
		$this->logEvents->dispatch(
			LogEvent::ConsentChanged,
			new ConsentChangedEvent( null, null, $typeKey, null, $version )
		);

		return $id;
	}

	/**
	 * Фиксирует подписание согласия законным представителем за ребёнка.
	 *
	 * @throws RuntimeException Если страница согласия не настроена
	 */
	public function recordGuardianConsent( ?int $appId, string $typeKey, int $forPersonId, RequestContextDTO $ctx ): int {
		$version = $this->getCurrentVersion( $typeKey );

		$id = $this->consentRepository->create( array(
			'application_id'       => $appId,
			'consent_type'         => $typeKey,
			'subject_role'         => 'guardian',
			'version'              => $version,
			'document_hash'        => $version,
			'ip_address'           => $ctx->ip,
			'user_agent'           => $ctx->userAgent,
			'accepted_at'          => $this->clock->now( 'mysql', true ),
			'signed_for_person_id' => $forPersonId ?: null,
		) );

		$this->auditService->recordAnonymous(
			AuditAction::ConsentSigned->value,
			'consent',
			$id,
			array( 'consent_type' => $typeKey, 'version' => $version, 'application_id' => $appId, 'subject_role' => 'guardian' )
		);
		$this->logEvents->dispatch(
			LogEvent::ConsentChanged,
			new ConsentChangedEvent( null, $forPersonId ?: null, $typeKey, null, $version )
		);

		return $id;
	}

	/**
	 * Привязывает согласия заявки к записям person по роли субъекта.
	 */
	public function bindToPersons( int $appId, array $personMap ): void {
		$this->consentRepository->bindApplicationConsentsToPersons( $appId, $personMap );
	}

	/**
	 * Отзывает согласие.
	 *
	 * @throws RuntimeException Если согласие не найдено
	 */
	public function withdraw( int $consentId, string $reason ): void {
		if ( null === $this->consentRepository->find( $consentId ) ) {
			throw new RuntimeException( "Согласие с ID {$consentId} не найдено." );
		}

		$consent = $this->consentRepository->find( $consentId );
		$this->consentRepository->withdraw( $consentId, $reason );

		$this->auditService->record(
			AuditAction::ConsentWithdrawn->value,
			'consent',
			$consentId,
			array( 'reason' => $reason )
		);
		$this->logEvents->dispatch(
			LogEvent::ConsentChanged,
			new ConsentChangedEvent(
				get_current_user_id() ?: null,
				$consent?->personId,
				$consent?->consentType ?? '',
				$consent?->documentHash,
				null,
			)
		);
	}

	/**
	 * Возвращает WP-страницу для указанного типа согласия, или null если не найдена.
	 */
	public function getPageForType( string $typeKey ): ?\WP_Post {
		try {
			return $this->requirePage( $typeKey );
		} catch ( RuntimeException $e ) {
			return null;
		}
	}

	private function requirePage( string $typeKey ): \WP_Post {
		$def = $this->consentDefinitions->findByKey( $typeKey );
		if ( ! $def ) {
			throw new RuntimeException( "Согласие '{$typeKey}' не найдено. Создайте его в настройках." );
		}

		$pageId = (int) ( $def['page_id'] ?? 0 );
		if ( $pageId <= 0 ) {
			throw new RuntimeException( "Страница для согласия '{$typeKey}' не создана." );
		}

		$page = get_post( $pageId );
		if ( ! $page instanceof \WP_Post || 'publish' !== $page->post_status ) {
			throw new RuntimeException( "Страница согласия не опубликована." );
		}

		return $page;
	}
}
