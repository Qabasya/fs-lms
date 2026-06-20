<?php

declare( strict_types=1 );

namespace Inc\Controllers\Person;

use Inc\Core\BaseController;
use Inc\Contracts\ServiceInterface;
use Inc\Enums\Settings\OptionName;
use Inc\Enums\Wp\PageRoutes;
use Inc\Enums\Wp\ShortCode;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Shared\Traits\TemplateRenderer;

/**
 * Class ProfileController
 *
 * Контроллер для управления личным кабинетом пользователя.
 *
 * @package Inc\Controllers
 * @implements ServiceInterface
 *
 * ### Основные обязанности:
 *
 * 1. **Маршрутизация профиля** — редирект незалогиненных пользователей со страницы профиля на вход.
 * 2. **Редирект со страницы входа** — перенаправление залогиненных пользователей со страницы входа в профиль.
 * 3. **Рендеринг профиля** — отображение личного кабинета через шорткод.
 *
 * ### Архитектурная роль:
 *
 * Использует PageRoutes для работы с URL (проверка текущей страницы, получение URL).
 * Делегирует рендеринг шаблонов трейту TemplateRenderer.
 */
class ProfileController extends BaseController implements ServiceInterface {

	use TemplateRenderer;  // Трейт с методом render() для подключения шаблонов

	public function __construct(
		private readonly PersonRepository       $personRepository,
		private readonly StudentRecordRepository $studentRecords,
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
		add_action( 'template_redirect', array( $this, 'handleRoutingAndPrivacy' ) );

		// Шорткод для вставки профиля на любую страницу
		add_shortcode( ShortCode::Profile->value, array( $this, 'renderProfileShortcode' ) );
	}

	/**
	 * Обрабатывает маршрутизацию и проверку доступа к страницам.
	 *
	 * @return void
	 */
	public function handleRoutingAndPrivacy(): void {
		// Если пользователь залогинен и находится на странице входа — редирект в профиль
		// is_user_logged_in() — проверяет авторизацию
		// isCurrent() — метод enum PageRoutes, проверяет соответствие текущей страницы
		if ( is_user_logged_in() && PageRoutes::SignIn->isCurrent() ) {
			wp_safe_redirect( PageRoutes::UserProfile->url() );
			exit;
		}

		// Если пользователь не залогинен и находится на странице профиля — редирект на вход
		if ( ! is_user_logged_in() && PageRoutes::UserProfile->isCurrent() ) {
			wp_safe_redirect( PageRoutes::SignIn->url() );
			exit;
		}

		// T2.27: Гейт кабинета для полностью отчисленных (политика 'block').
		if ( is_user_logged_in() && PageRoutes::UserProfile->isCurrent() ) {
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
	 * Рендерит личный кабинет пользователя через шорткод.
	 *
	 * @return string HTML-контент профиля
	 */
	public function renderProfileShortcode(): string {
		// Проверка авторизации
		if ( ! is_user_logged_in() ) {
			return '<p>Доступ ограничен. Пожалуйста, авторизуйтесь.</p>';
		}

		// wp_get_current_user() — возвращает объект текущего пользователя
		$current_user = wp_get_current_user();

		// Буферизация вывода (трейт render() выводит напрямую, а шорткод должен возвращать строку)
		ob_start();

		$this->render(
			'frontend/profile',
			array(
				'user' => $current_user,
			)
		);

		// ob_get_clean() — получаем содержимое буфера и очищаем его
		return (string) ob_get_clean();
	}
}