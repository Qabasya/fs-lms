<?php

declare( strict_types=1 );

namespace Inc\Modules\AdSync\Controllers;

use Inc\Modules\AdSync\Services\AdHmacAuth;
use Inc\Modules\AdSync\Services\AdProvisioningService;
use Inc\Modules\AdSync\Services\AdReconcileService;

/**
 * Class AdSyncRestController
 *
 * REST-эндпоинты, которые Python-сервис из локальной сети опрашивает (pull):
 *   GET  /wp-json/fs-lms/v1/ad/jobs  — забрать готовые задания (с логином/паролем/группой).
 *   POST /wp-json/fs-lms/v1/ad/ack   — отчитаться о выполнении (done/failed).
 * Аутентификация — HMAC (см. AdHmacAuth). Регистрируется только при включённом модуле.
 *
 * @package Inc\Modules\AdSync\Controllers
 */
class AdSyncRestController {

	private const NAMESPACE = 'fs-lms/v1';

	public function __construct(
		private readonly AdProvisioningService $service,
		private readonly AdReconcileService    $reconcile,
		private readonly AdHmacAuth            $auth,
	) {}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'registerRoutes' ) );
	}

	public function registerRoutes(): void {
		$permission = fn( \WP_REST_Request $request ): bool => $this->auth->verify( $request );

		register_rest_route( self::NAMESPACE, '/ad/jobs', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'getJobs' ),
			'permission_callback' => $permission,
		) );

		register_rest_route( self::NAMESPACE, '/ad/ack', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'postAck' ),
			'permission_callback' => $permission,
			'args'                => array(
				'id'     => array( 'required' => true ),
				'status' => array( 'required' => true ),
			),
		) );

		register_rest_route( self::NAMESPACE, '/ad/active-usernames', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'getActiveUsernames' ),
			'permission_callback' => $permission,
		) );
	}

	/** GET /ad/jobs → { jobs: [ {id, event, idempotency_key, username, password, first, last, subject_key}, … ] } (deprovision: только username) */
	public function getJobs( \WP_REST_Request $request ): \WP_REST_Response {
		$limit = (int) ( $request->get_param( 'limit' ) ?: 50 );
		$limit = max( 1, min( 200, $limit ) );

		return new \WP_REST_Response( array( 'jobs' => $this->service->pendingJobs( $limit ) ), 200 );
	}

	/** POST /ad/ack { id, status: done|failed, error?, sam_account_name? } → { ok: true } */
	public function postAck( \WP_REST_Request $request ): \WP_REST_Response {
		$id     = (int) $request->get_param( 'id' );
		$status = strtolower( (string) $request->get_param( 'status' ) );
		$error  = (string) ( $request->get_param( 'error' ) ?? '' );

		if ( $id <= 0 ) {
			return new \WP_REST_Response( array( 'ok' => false, 'error' => 'bad id' ), 400 );
		}

		$ok = in_array( $status, array( 'done', 'ok', 'sent', 'success' ), true );
		$this->service->ack( $id, $ok, $error );

		return new \WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/** GET /ad/active-usernames → { usernames: ["i.petrov", …] } — «кто должен жить» (для сверки/«пылесоса»). */
	public function getActiveUsernames( \WP_REST_Request $request ): \WP_REST_Response {
		return new \WP_REST_Response( array( 'usernames' => $this->reconcile->activeUsernames() ), 200 );
	}
}
