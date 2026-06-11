<?php

declare( strict_types=1 );

namespace Inc\Callbacks;

use Inc\Core\BaseController;
use Inc\Enums\Capability;
use Inc\Enums\ExportTarget;
use Inc\Enums\Nonce;
use Inc\Services\Export\ExportService;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

class LogsCallbacks extends BaseController {

	use Authorizer;
	use Sanitizer;

	public function __construct(
		private readonly ExportService $exportService,
	) {
		parent::__construct();
	}

	// ==================== Экспорт данных ====================

	public function ajaxExportGroups(): void {
		$this->authorize( Nonce::Manager, Capability::Admin );
		$ids = array_filter( array_map( 'intval', (array) ( $_POST['ids'] ?? array() ) ) );
		$url = $this->exportService->run( ExportTarget::Groups, $ids ? array( 'ids' => $ids ) : array() );
		$this->success( array( 'url' => $url ) );
	}

	public function ajaxExportStudents(): void {
		$this->authorize( Nonce::Manager, Capability::Admin );
		$ids = array_filter( array_map( 'intval', (array) ( $_POST['ids'] ?? array() ) ) );
		$mode = $ids ? 'single' : 'bulk';
		$url  = $this->exportService->run( ExportTarget::Students, $ids ? array( 'ids' => $ids ) : array(), $mode );
		$this->success( array( 'url' => $url ) );
	}

	public function ajaxExportParents(): void {
		$this->authorize( Nonce::Manager, Capability::Admin );
		$ids = array_filter( array_map( 'intval', (array) ( $_POST['ids'] ?? array() ) ) );
		$mode = $ids ? 'single' : 'bulk';
		$url  = $this->exportService->run( ExportTarget::Parents, $ids ? array( 'ids' => $ids ) : array(), $mode );
		$this->success( array( 'url' => $url ) );
	}

	public function ajaxExportArchive(): void {
		$this->authorize( Nonce::Manager, Capability::Admin );
		$ids = array_filter( array_map( 'intval', (array) ( $_POST['ids'] ?? array() ) ) );
		$url = $this->exportService->run( ExportTarget::Archive, $ids ? array( 'ids' => $ids ) : array() );
		$this->success( array( 'url' => $url ) );
	}

	// ==================== Экспорт журналов ====================

	public function ajaxExportEntityAuditLog(): void {
		$this->authorize( Nonce::Manager, Capability::Admin );
		$filters = $this->logFilters( array( 'operation', 'entity_type', 'actor_user_id', 'date_from', 'date_to' ) );
		$url     = $this->exportService->run( ExportTarget::LogEntityAudit, $filters );
		$this->success( array( 'url' => $url ) );
	}

	public function ajaxExportEnrollmentLog(): void {
		$this->authorize( Nonce::Manager, Capability::Admin );
		$filters = $this->logFilters( array( 'action_filter', 'actor_user_id', 'date_from', 'date_to' ) );
		$url     = $this->exportService->run( ExportTarget::LogEnrollment, $filters );
		$this->success( array( 'url' => $url ) );
	}

	public function ajaxExportAuditLog(): void {
		$this->authorize( Nonce::Manager, Capability::Admin );
		$filters = $this->logFilters( array( 'action_filter', 'actor_user_id', 'date_from', 'date_to' ) );
		$url     = $this->exportService->run( ExportTarget::LogEnrollment, $filters );
		$this->success( array( 'url' => $url ) );
	}

	public function ajaxExportPiiLog(): void {
		$this->authorize( Nonce::Manager, Capability::Admin );
		$filters = $this->logFilters( array( 'actor_user_id', 'person_id', 'date_from', 'date_to' ) );
		$url     = $this->exportService->run( ExportTarget::LogPiiAccess, $filters );
		$this->success( array( 'url' => $url ) );
	}

	public function ajaxExportExportLog(): void {
		$this->authorize( Nonce::Manager, Capability::Admin );
		$filters = $this->logFilters( array( 'actor_user_id', 'data_type', 'date_from', 'date_to' ) );
		$url     = $this->exportService->run( ExportTarget::LogExport, $filters );
		$this->success( array( 'url' => $url ) );
	}

	public function ajaxExportDataChangeLog(): void {
		$this->authorize( Nonce::Manager, Capability::Admin );
		$filters = $this->logFilters( array( 'actor_user_id', 'person_id', 'date_from', 'date_to' ) );
		$url     = $this->exportService->run( ExportTarget::LogDataChange, $filters );
		$this->success( array( 'url' => $url ) );
	}

	public function ajaxExportConsentChangeLog(): void {
		$this->authorize( Nonce::Manager, Capability::Admin );
		$filters = $this->logFilters( array( 'person_id', 'consent_type', 'date_from', 'date_to' ) );
		$url     = $this->exportService->run( ExportTarget::LogConsentChange, $filters );
		$this->success( array( 'url' => $url ) );
	}

	public function ajaxExportEmailLog(): void {
		$this->authorize( Nonce::Manager, Capability::Admin );
		$filters = $this->logFilters( array( 'email_type', 'status', 'person_id', 'date_from', 'date_to' ) );
		$url     = $this->exportService->run( ExportTarget::LogEmail, $filters );
		$this->success( array( 'url' => $url ) );
	}

	public function ajaxExportDeletionLog(): void {
		$this->authorize( Nonce::Manager, Capability::Admin );
		$filters = $this->logFilters( array( 'actor_user_id', 'entity_type', 'date_from', 'date_to' ) );
		$url     = $this->exportService->run( ExportTarget::LogDeletion, $filters );
		$this->success( array( 'url' => $url ) );
	}

	public function ajaxExportAuthLog(): void {
		$this->authorize( Nonce::Manager, Capability::Admin );
		$filters = $this->logFilters( array( 'action_filter', 'result', 'date_from', 'date_to' ) );
		$url     = $this->exportService->run( ExportTarget::LogAuth, $filters );
		$this->success( array( 'url' => $url ) );
	}

	/**
	 * Собирает ненулевые фильтры из $_POST по списку ключей.
	 *
	 * @param string[] $keys
	 * @return array<string, mixed>
	 */
	private function logFilters( array $keys ): array {
		$map = array(
			'action_filter' => fn() => $this->sanitizeKey( 'action_filter' ),
			'operation'     => fn() => $this->sanitizeKey( 'operation' ),
			'entity_type'   => fn() => $this->sanitizeKey( 'entity_type' ),
			'actor_user_id' => fn() => $this->sanitizeInt( 'actor_id' ) ?: null,
			'person_id'     => fn() => $this->sanitizeInt( 'person_id' ) ?: null,
			'data_type'     => fn() => $this->sanitizeKey( 'data_type' ),
			'consent_type'  => fn() => $this->sanitizeKey( 'consent_type' ),
			'email_type'    => fn() => $this->sanitizeKey( 'email_type' ),
			'status'        => fn() => $this->sanitizeKey( 'status' ),
			'result'        => fn() => $this->sanitizeKey( 'result' ),
			'date_from'     => fn() => $this->sanitizeText( 'date_from' ),
			'date_to'       => fn() => $this->sanitizeText( 'date_to' ),
		);

		$filters = array();
		foreach ( $keys as $key ) {
			if ( isset( $map[ $key ] ) ) {
				$value = ( $map[ $key ] )();
				if ( $value !== null && $value !== '' ) {
					$filters[ $key ] = $value;
				}
			}
		}
		return $filters;
	}
}
