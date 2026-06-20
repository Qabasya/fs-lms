<?php

namespace Inc\Controllers\Auth;

use Exception;
use Inc\Callbacks\Auth\AuthCallbacks;
use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\Enums\Auth\AuthProvider;
use Inc\Enums\Wp\PageRoutes;
use Inc\Enums\Wp\ShortCode;
use Inc\Enums\Access\Capability;
use Inc\Services\Auth\AuthStrategyRegistry;
use Inc\Services\Auth\ProviderResolver;
use Inc\Shared\PluginLogger;
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

	use ErrorHandler;  // sendError() — dual-context dispatch (AJAX / wp_die)

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

		add_filter( 'show_admin_bar', array( $this, 'handleAdminBarVisibility' ) );
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
			PluginLogger::exception( 'AuthController', $e, array( 'provider' => $provider?->value ) );
			$this->sendError( 'auth_error', 'Техническая ошибка при обработке ответа', 500 );
		}
	}

	/**
	 * Определяет, куда перенаправить пользователя после успешного входа.
	 *
	 * @param string  $redirect_url Дефолтный PageRoutes редиректа.
	 * @param UserDTO $user_dto     Объект с данными пользователя.
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

		return PageRoutes::UserProfile->url();
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

	/**
	 * Управляет видимостью админ-бара на основе возможностей пользователя.
	 * * @param bool $show Текущее состояние видимости админ-бара.
	 *
	 * @return bool Измененное состояние видимости.
	 */
	public function handleAdminBarVisibility( bool $show ): bool {
		// Если пользователь не авторизован, скрываем в любом случае
		if ( ! is_user_logged_in() ) {
			return false;
		}

		// Админ-бар показываем администраторам и сотрудникам LMS (преподаватели,
		// учебный офис) — у них есть доступ в админку. Студентам/родителям скрываем.
		$staff_caps = array(
			Capability::Admin->value,
			Capability::ManageLMSAssignments->value,
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
