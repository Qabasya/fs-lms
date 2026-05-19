<?php

namespace Inc\Controllers;

use Exception;
use Inc\Callbacks\AuthCallbacks;
use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\Enums\AuthProvider;
use Inc\Enums\PageRoutes;
use Inc\Services\AuthService\AuthStrategyRegistry;
use Inc\Services\AuthService\ProviderResolver;
use Inc\Shared\Traits\ErrorHandler;

/**
 * Class AuthController
 *
 * Контроллер аутентификации через социальные сети (Hybridauth).
 *
 * @package Inc\Controllers
 * @implements ServiceInterface
 *
 * ### Основные обязанности:
 *
 * 1. **Маршрутизация** — обработка кастомных маршрутов для входа через соцсети (/lms-auth/vk, /lms-auth/google).
 * 2. **Инициализация входа** — перенаправление на страницу авторизации провайдера.
 * 3. **Обработка callback'а** — получение данных пользователя после успешной авторизации.
 *
 * ### Архитектурная роль:
 *
 * Делегирует бизнес-логику стратегиям аутентификации (получаемым из AuthStrategyRegistry),
 * определение провайдера — ProviderResolver. Является точкой входа для всего функционала
 * аутентификации через соцсети.
 */
class AuthController extends BaseController implements ServiceInterface {

	use ErrorHandler;  // Трейт с методами logException(), sendError()

	// Префикс маршрутов для аутентификации (PageRoutes: /lms-auth/{provider})
	private const string ROUTE_PREFIX = 'lms-auth';

	public function __construct(
		private readonly AuthCallbacks $callbacks,
		private readonly ProviderResolver $provider_resolver,
		private readonly AuthStrategyRegistry $strategy_registry,
	) {
		parent::__construct();
	}

	/**
	 * Регистрирует все хуки и шорткоды контроллера.
	 *
	 * @return void
	 */
	public function register(): void {
		// 'template_redirect' — хук, срабатывающий перед загрузкой шаблона темы
		add_action( 'template_redirect', array( $this, 'handleAuthRoutes' ) );

		add_filter( 'lms_auth_redirect_url', array( $this, 'filterRedirectUrl' ), 10, 2 );
		add_filter( 'get_avatar_url', array( $this, 'filterAvatarUrl' ), 10, 3 );

		// Шорткод для тестовой страницы — доступен только в режиме отладки
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			add_shortcode( 'lms_auth_test', array( $this->callbacks, 'renderAuthTestPage' ) );
		}
	}

	/**
	 * Обрабатывает кастомные маршруты аутентификации.
	 *
	 * @return void
	 */
	public function handleAuthRoutes(): void {
		// wp_parse_url() — аналог parse_url() с поддержкой WordPress-фильтров
		// wp_unslash() — удаляет экранирование слешей
		$path = trim( wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ), '/' );

		// str_starts_with() — проверяет начало строки (PHP 8.0)
		if ( ! str_starts_with( $path, self::ROUTE_PREFIX . '/' ) ) {
			return;
		}

		// explode() — разбивает строку по разделителю '/'
		// array_filter() — удаляет пустые элементы
		// array_values() — переиндексирует массив
		$parts  = array_values( array_filter( explode( '/', $path ) ) );
		$action = $parts[1] ?? null;

		// Маршрут: /lms-auth/callback — обработка ответа от провайдера
		if ( 'callback' === $action ) {
			$this->processCallback();
			return;
		}

		// Маршрут: /lms-auth/login — страница выбора провайдера (заглушка)
		if ( 'login' === $action ) {
			return;
		}

		// Маршрут: /lms-auth/{provider} — вход через конкретного провайдера
		$provider = AuthProvider::fromRequest( (string) $action );
		if ( $provider ) {
			$this->processLogin( $provider );
		}
	}

	/**
	 * Инициализирует процесс входа через социальную сеть.
	 *
	 * @param AuthProvider $provider Провайдер (vk, google, github)
	 *
	 * @return void
	 */
	private function processLogin( AuthProvider $provider ): void {
		// Получение стратегии из реестра
		$strategy = $this->strategy_registry->get( $provider );

		if ( ! $strategy ) {
			$this->sendError( 'unknown_provider', 'Провайдер не поддерживается или не настроен' );
			return;
		}

		// login() — перенаправляет на страницу авторизации провайдера
		$strategy->login();
	}

	/**
	 * Обрабатывает callback-запрос от провайдера после авторизации.
	 *
	 * @return void
	 */
	private function processCallback(): void {
		// Определяем провайдера из параметров callback'а
		$provider = $this->provider_resolver->fromCallback();
		$strategy = $this->strategy_registry->get( $provider );

		if ( ! $strategy ) {
			$this->sendError( 'unknown_provider', 'Не удалось определить стратегию для callback' );
			return;
		}

		try {
			// authenticate() — получает профиль и возвращает UserDTO
			$user = $strategy->authenticate();

			if ( $user ) {
				// apply_filters() — позволяет переопределить PageRoutes редиректа
				$redirect = apply_filters( 'lms_auth_redirect_url', home_url( '/wp-admin/profile.php' ), $user );
				// wp_safe_redirect() — безопасный редирект (только локальные PageRoutes)
				wp_safe_redirect( $redirect );
				exit;
			}

			$this->sendError( 'auth_failed', 'Ошибка авторизации через соцсеть', 401 );

		} catch ( Exception $e ) {
			// Логирование ошибки
			$this->logException(
				$e,
				array(
					'provider'  => $provider?->value,
					'component' => 'auth',
				)
			);
			$this->sendError( 'auth_error', 'Техническая ошибка при обработке ответа', 500 );
		}
	}

	/**
	 * Определяет, куда перенаправить пользователя после успешного входа.
	 *
	 * @param string   $redirect_url Дефолтный PageRoutes редиректа.
	 * @param UserDTO  $user_dto     Объект с данными пользователя.
	 * @return string
	 */
	public function filterRedirectUrl( string $redirect_url, $user_dto ): string {
		// Получаем текущего вошедшего пользователя WordPress
		$wp_user = wp_get_current_user();

		if ( ! $wp_user->exists() ) {
			return home_url();
		}

		// Если это администратор или редактор/преподаватель — пускаем в админку
		if ( array_intersect( array( 'administrator', 'editor' ), $wp_user->roles ) ) {
			return admin_url(); // или дефолтный profile.php
		}

		return PageRoutes::USER_PROFILE->url();
	}

	/**
	 * Подменяет стандартный PageRoutes Gravatar на сохраненную ссылку из соцсети.
	 */
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

		// Читаем правильный ключ, который пишет твой UserRepository
		$social_avatar = get_user_meta( $user_id, 'fs_avatar_url', true );

		return ! empty( $social_avatar ) ? esc_url_raw( $social_avatar ) : $url;
	}
}
