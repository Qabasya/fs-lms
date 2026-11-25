<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories;

use Inc\Enums\Wp\MetaKeys;

/** Продление существующего токена WordPress без создания нового входа. */
class UserSessionRepository {

	public const string LMS_SESSION = 'fs_lms_rolling';

	private readonly \wpdb $wpdb;

	public function __construct( ?\wpdb $wpdb = null ) {
		$this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
	}

	public function extend( int $userId, string $token, int $now, int $expiration ): bool {
		$metaKey = MetaKeys::SessionTokens->value;
		// WP_Session_Tokens хранит сессию под SHA-256, а не под самим токеном.
		$verifier = hash( 'sha256', $token );
		for ( $attempt = 0; $attempt < 2; ++$attempt ) {
			$row = $this->wpdb->get_row( $this->wpdb->prepare(
				'SELECT umeta_id, meta_value FROM %i WHERE user_id = %d AND meta_key = %s ORDER BY umeta_id LIMIT 1',
				$this->wpdb->usermeta, $userId, $metaKey
			), ARRAY_A );
			if ( ! $row ) {
				return false;
			}

			$sessions = maybe_unserialize( $row['meta_value'] );
			$session  = is_array( $sessions ) ? ( $sessions[ $verifier ] ?? null ) : null;
			$current  = (int) ( $session['expiration'] ?? 0 );
			if ( empty( $session[ self::LMS_SESSION ] ) || $current <= $now || $current >= $expiration ) {
				return false;
			}

			$sessions[ $verifier ]['expiration'] = $expiration;
			// Только UPDATE с предыдущим значением. Обычный update_user_meta() может заново
			// создать удалённую строку, если пользователь одновременно вышел из аккаунта.
			$updated = $this->wpdb->query( $this->wpdb->prepare(
				'UPDATE %i SET meta_value = %s WHERE umeta_id = %d AND BINARY meta_value = %s',
				$this->wpdb->usermeta, maybe_serialize( $sessions ), (int) $row['umeta_id'], $row['meta_value']
			) );
			if ( 1 === $updated ) {
				wp_cache_delete( $userId, 'user_meta' );
				return true;
			}
			if ( 0 !== $updated ) {
				return false;
			}
			// Другое устройство могло обновить свою сессию. Считываем актуальный набор.
		}
		return false;
	}
}
