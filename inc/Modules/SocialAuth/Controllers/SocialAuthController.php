<?php

declare( strict_types=1 );

namespace Inc\Modules\SocialAuth\Controllers;

use Exception;
use Inc\Modules\SocialAuth\Callbacks\SocialAuthCallbacks;
use Inc\Modules\SocialAuth\Enums\AuthProvider;
use Inc\Modules\SocialAuth\Services\AuthStrategyRegistry;
use Inc\Modules\SocialAuth\Services\ProviderResolver;
use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\Enums\Wp\PageRoutes;
use Inc\Enums\Access\Capability;
use Inc\Shared\PluginLogger;
use Inc\Shared\Traits\ErrorHandler;

class SocialAuthController extends BaseController implements ServiceInterface {

	use ErrorHandler;

	private const string ROUTE_PREFIX = 'lms-auth';

	public function __construct(
		private readonly SocialAuthCallbacks $callbacks,
		private readonly ProviderResolver $provider_resolver,
		private readonly AuthStrategyRegistry $strategy_registry,
	) {
		parent::__construct();
	}

	public function register(): void {
		add_action( 'template_redirect', array( $this, 'handleAuthRoutes' ) );
		add_filter( 'lms_auth_redirect_url', array( $this, 'filterRedirectUrl' ), 10, 2 );
		add_filter( 'get_avatar_url', array( $this, 'filterAvatarUrl' ), 10, 3 );
		add_filter( 'show_admin_bar', array( $this, 'handleAdminBarVisibility' ) );
	}

	public function handleAuthRoutes(): void {
		$path = trim( wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ), '/' );

		if ( ! str_starts_with( $path, self::ROUTE_PREFIX . '/' ) ) {
			return;
		}

		$parts  = array_values( array_filter( explode( '/', $path ) ) );
		$action = $parts[1] ?? null;

		if ( 'callback' === $action ) {
			$this->processCallback();
			return;
		}

		if ( 'login' === $action ) {
			return;
		}

		$provider = AuthProvider::fromRequest( (string) $action );
		if ( $provider ) {
			$this->processLogin( $provider );
		}
	}

	private function processLogin( AuthProvider $provider ): void {
		$strategy = $this->strategy_registry->get( $provider );

		if ( ! $strategy ) {
			$this->sendError( 'unknown_provider', 'Провайдер не поддерживается или не настроен' );
			return;
		}

		$strategy->login();
	}

	private function processCallback(): void {
		$provider = $this->provider_resolver->fromCallback();
		$strategy = $this->strategy_registry->get( $provider );

		if ( ! $strategy ) {
			$this->sendError( 'unknown_provider', 'Не удалось определить стратегию для callback' );
			return;
		}

		try {
			$user = $strategy->authenticate();

			if ( $user ) {
				$redirect = apply_filters( 'lms_auth_redirect_url', home_url( '/wp-admin/profile.php' ), $user );
				wp_safe_redirect( $redirect );
				exit;
			}

			$this->sendError( 'auth_failed', 'Ошибка авторизации через соцсеть', 401 );

		} catch ( Exception $e ) {
			PluginLogger::exception( 'SocialAuthController', $e, array( 'provider' => $provider?->value ) );
			$this->sendError( 'auth_error', 'Техническая ошибка при обработке ответа', 500 );
		}
	}

	public function filterRedirectUrl( string $redirect_url, $user_dto ): string {
		$wp_user = wp_get_current_user();

		if ( ! $wp_user->exists() ) {
			return home_url();
		}

		if ( array_intersect( array( 'administrator', 'editor' ), $wp_user->roles ) ) {
			return admin_url();
		}

		return PageRoutes::UserProfile->url();
	}

	public function filterAvatarUrl( string $url, $id_or_email, array $args ): string {
		$user_id = 0;

		if ( is_numeric( $id_or_email ) ) {
			$user_id = (int) $id_or_email;
		} elseif ( is_object( $id_or_email ) && isset( $id_or_email->user_id ) && $id_or_email->user_id > 0 ) {
			$user_id = (int) $id_or_email->user_id;
		} elseif ( is_string( $id_or_email ) && ( $user = get_user_by( 'email', $id_or_email ) ) ) {
			$user_id = $user->ID;
		}

		if ( $user_id <= 0 ) {
			return $url;
		}

		$social_avatar = get_user_meta( $user_id, 'fs_avatar_url', true );

		return ! empty( $social_avatar ) ? esc_url_raw( $social_avatar ) : $url;
	}

	public function handleAdminBarVisibility( bool $show ): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		$staff_caps = array(
			Capability::Admin->value,
			Capability::ManageLmsPlatform->value,
			Capability::ManageApplications->value,
		);

		foreach ( $staff_caps as $cap ) {
			if ( current_user_can( $cap ) ) {
				return $show;
			}
		}

		return false;
	}
}
