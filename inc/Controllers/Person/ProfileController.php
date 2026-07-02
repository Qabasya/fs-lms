<?php

declare( strict_types=1 );

namespace Inc\Controllers\Person;

use Inc\Core\BaseController;
use Inc\Contracts\ServiceInterface;
use Inc\Enums\Settings\OptionName;
use Inc\Enums\Wp\PageRoutes;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Profile\ProfileViewResolver;

/**
 * Class ProfileController
 *
 * Контроллер личного кабинета (`/profile/`) — единая точка входа для всех ролей.
 *
 * @package Inc\Controllers
 * @implements ServiceInterface
 *
 * ### Основные обязанности:
 *
 * 1. **Маршрутизация и приватность** — редиректы вход↔профиль; гейт отчисленных;
 *    офисные роли (FSOffice/FSMethodist/FSMarket) → в админку WP (фронт-кабинета у них нет).
 * 2. **Полноэкранный SPA** — перехват template_include и рендер каркаса кабинета.
 *    Состав по роли (сайдбар/экраны/режим) собирает ProfileViewResolver → window.fsProfile
 *    (локализация в Enqueue), сами экраны рисует фронтовый бандл profile.min.js.
 *
 * ### Архитектурная роль:
 *
 * Кабинет — часть ядра (не отключаемый модуль). Глубокие экраны (журнал/КТП/проверка)
 * живут здесь же в SPA; доменные операции идут через AJAX-хуки ядра (Lms).
 */
class ProfileController extends BaseController implements ServiceInterface {

	public function __construct(
		private readonly PersonRepository        $personRepository,
		private readonly StudentRecordRepository $studentRecords,
		private readonly ProfileViewResolver     $resolver,
	) {
		parent::__construct();
	}

	public function register(): void {
		// 'template_redirect' — редиректы/гейты до загрузки шаблона.
		add_action( 'template_redirect', array( $this, 'handleRoutingAndPrivacy' ) );

		// 'template_include' — полная подмена шаблона страницы профиля каркасом SPA.
		add_filter( 'template_include', array( $this, 'loadProfileTemplate' ) );
	}

	/**
	 * Редиректы и проверки доступа к странице профиля.
	 *
	 * @return void
	 */
	public function handleRoutingAndPrivacy(): void {
		// Залогинен и на странице входа → в профиль.
		if ( is_user_logged_in() && PageRoutes::SignIn->isCurrent() ) {
			wp_safe_redirect( PageRoutes::UserProfile->url() );
			exit;
		}

		// Не залогинен и на профиле → на вход.
		if ( ! is_user_logged_in() && PageRoutes::UserProfile->isCurrent() ) {
			wp_safe_redirect( PageRoutes::SignIn->url() );
			exit;
		}

		if ( is_user_logged_in() && PageRoutes::UserProfile->isCurrent() ) {
			$user = wp_get_current_user();

			// Роли без фронт-витрины (методист/маркетолог) работают в админке WP.
			// FSOffice/FSTeacher/учащиеся/чистый администратор (T12.1) имеют витрину → остаются на /profile/.
			// Роль берём из resolver->context() — единственный источник истины (в т.ч. для admin-суперсета).
			if ( null === $this->resolver->viewFor( $this->resolver->context( $user->ID )->role ) ) {
				wp_safe_redirect( admin_url() );
				exit;
			}

			// Гейт кабинета для полностью отчисленных (политика 'block').
			$policy = get_option( OptionName::ExpulsionRetentionPolicy->value, 'retain' );
			if ( 'block' === $policy ) {
				$person = $this->personRepository->findByWpUserId( get_current_user_id() );
				if ( $person ) {
					$activeRecords = $this->studentRecords->findActiveByStudent( $person->id );
					if ( empty( $activeRecords ) ) {
						wp_safe_redirect( home_url( '/' ) );
						exit;
					}
				}
			}
		}
	}

	/**
	 * Подменяет шаблон страницы профиля полноэкранным каркасом SPA.
	 *
	 * @param string $template Путь к шаблону темы.
	 *
	 * @return string
	 */
	public function loadProfileTemplate( string $template ): string {
		if ( ! PageRoutes::UserProfile->isCurrent() ) {
			return $template;
		}
		return $this->path( 'templates/frontend/profile.php' );
	}
}
