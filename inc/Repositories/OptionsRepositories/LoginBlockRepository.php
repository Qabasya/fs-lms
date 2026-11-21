<?php

declare( strict_types=1 );

namespace Inc\Repositories\OptionsRepositories;

use Inc\Enums\Settings\OptionName;
use Inc\Repositories\WPDBRepositories\ExamLockRepository;

/** Постоянные блокировки хранятся отдельно, не загружаются на каждой странице. */
class LoginBlockRepository {
	public function __construct( private readonly ExamLockRepository $locks ) {}

	public function isBlocked( string $key ): bool {
		return false !== get_option( $this->optionName( $key ), false );
	}

	/** Атомарный подсчёт по конкретному IP или аккаунту, включая одновременные запросы. */
	public function recordFailure( string $key, int $limit, int $window ): void {
		$this->synchronized( $key, function () use ( $key, $limit, $window ): void {
			if ( $this->isBlocked( $key ) ) {
				return;
			}
			$counterKey = $this->counterKey( $key );
			$this->refreshCounterCache( $counterKey );
			$state = get_transient( $counterKey );
			$now = time();
			if ( ! is_array( $state ) || (int) ( $state['until'] ?? 0 ) <= $now ) {
				$state = array( 'count' => 0, 'until' => $now + $window );
			}
			++$state['count'];
			if ( $state['count'] >= $limit ) {
				add_option( $this->optionName( $key ), (string) $now, '', false );
				if ( ! $this->isBlocked( $key ) ) {
					throw new \RuntimeException( 'Не удалось сохранить блокировку входа.' );
				}
				delete_transient( $counterKey );
				return;
			}
			set_transient( $counterKey, $state, max( 1, $state['until'] - $now ) );
		} );
	}

	/** Ступенчатая блокировка IP; запросы во время паузы её не продлевают. */
	public function recordTemporaryFailure( string $key, int $limit, int $window, array $steps, int $memory, string $login = '' ): void {
		$this->synchronized( $key, function () use ( $key, $limit, $window, $steps, $memory, $login ): void {
			$counterKey = $this->counterKey( $key );
			$this->refreshCounterCache( $counterKey );
			$state = get_transient( $counterKey );
			$now = time();
			$state = is_array( $state ) ? $state : array();
			if ( (int) ( $state['locked_until'] ?? 0 ) > $now ) {
				return;
			}
			if ( (int) ( $state['until'] ?? 0 ) <= $now ) {
				$state['count'] = 0;
				$state['until'] = $now + $window;
				$state['switches'] = 0;
				$state['logins'] = array();
				unset( $state['last_login'] );
			}
			$state['count'] = (int) ( $state['count'] ?? 0 ) + 1;
			$loginHash = hash( 'sha256', mb_strtolower( trim( $login ) ) );
			if ( isset( $state['last_login'] ) && $state['last_login'] !== $loginHash ) {
				$state['switches'] = (int) ( $state['switches'] ?? 0 ) + 1;
			}
			$state['last_login'] = $loginHash;
			$state['logins'][ $loginHash ] = true;
			if ( $state['count'] >= $limit || (int) ( $state['switches'] ?? 0 ) > 2 ) {
				$state['trigger'] = (int) ( $state['switches'] ?? 0 ) > 2 ? 'login_switches' : 'failures';

				$state['level'] = min( (int) ( $state['level'] ?? 0 ) + 1, count( $steps ) );
				$state['locked_until'] = $now + $steps[ $state['level'] - 1 ];
				$state['count'] = 0;
				$state['until'] = $state['locked_until'];
			}
			set_transient( $counterKey, $state, max( $state['until'], (int) ( $state['locked_until'] ?? 0 ) ) - $now + $memory );
		} );
	}

	public function ipDiagnostics( string $key ): array {
		$state = get_transient( $this->counterKey( $key ) );
		if ( ! is_array( $state ) ) { return array(); }
		return array(
			'ip_failures' => (int) ( $state['count'] ?? 0 ),
			'ip_login_switches' => (int) ( $state['switches'] ?? 0 ),
			'ip_distinct_logins' => count( $state['logins'] ?? array() ),
			'ip_lock_level' => (int) ( $state['level'] ?? 0 ),
			'ip_lock_trigger' => (string) ( $state['trigger'] ?? '' ),
			'ip_retry_seconds' => $this->retrySeconds( $key ),
		);
	}

	public function retrySeconds( string $key ): int {
		$state = get_transient( $this->counterKey( $key ) );
		return max( 0, (int) ( is_array( $state ) ? ( $state['locked_until'] ?? 0 ) : 0 ) - time() );
	}

	/** Возвращает true, если постоянная блокировка действительно существовала. */
	public function unblock( string $key ): bool {
		return $this->synchronized( $key, function () use ( $key ): bool {
			$wasBlocked = $this->isBlocked( $key );
			delete_option( $this->optionName( $key ) );
			if ( $this->isBlocked( $key ) ) {
				throw new \RuntimeException( 'Не удалось снять блокировку входа.' );
			}
			delete_transient( $this->counterKey( $key ) );
			return $wasBlocked;
		} );
	}

	/** Успешный вход сбрасывает только незавершённый счётчик, не блокировку. */
	public function clearFailures( string $key ): void {
		$this->synchronized( $key, function () use ( $key ): void {
			delete_transient( $this->counterKey( $key ) );
		} );
	}

	private function synchronized( string $key, callable $callback ): mixed {
		$name = 'fs_login_' . substr( hash( 'sha256', $key ), 0, 32 );
		if ( ! $this->locks->acquire( $name, 3 ) ) {
			throw new \RuntimeException( 'Не удалось безопасно обновить защиту входа.' );
		}
		try {
			// Предыдущая проверка в этом запросе могла закешировать отсутствие записи,
			// пока другой процесс уже успел заблокировать этот IP или аккаунт.
			wp_cache_delete( $this->optionName( $key ), 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			return $callback();
		} finally {
			$this->locks->release( $name );
		}
	}

	private function optionName( string $key ): string {
		return OptionName::LoginBlockPrefix->value . $this->hash( $key );
	}

	private function counterKey( string $key ): string {
		return 'fs_lms_login_abuse_' . $this->hash( $key );
	}

	/** Под блокировкой перечитать состояние, обновлённое другим PHP-процессом. */
	private function refreshCounterCache( string $key ): void {
		if ( wp_using_ext_object_cache() ) {
			// Удалять transient здесь нельзя: при Redis это удалит само значение.
			wp_cache_get( $key, 'transient', true );
			return;
		}
		wp_cache_delete( '_transient_' . $key, 'options' );
		wp_cache_delete( '_transient_timeout_' . $key, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	private function hash( string $key ): string {
		return hash_hmac( 'sha256', $key, defined( 'FS_LMS_HASH_SALT' ) ? FS_LMS_HASH_SALT : wp_salt( 'auth' ) );
	}
}
