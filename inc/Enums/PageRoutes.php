<?php

namespace Inc\Enums;

/**
 * Enum PageRoutes
 *
 * Перечисление маршрутов (slug) для служебных страниц плагина.
 *
 * @package Inc\Enums
 *
 * ### Основные обязанности:
 *
 * 1. **Хранение слагов страниц** — централизованное хранение идентификаторов страниц.
 * 2. **Генерация URL** — построение полного URL для страницы.
 * 3. **Проверка текущей страницы** — определение, находится ли пользователь на этой странице.
 *
 * ### Архитектурная роль:
 *
 * Используется в AuthPageController, ProfileController и PageGeneratorService
 * для единообразной работы со служебными страницами плагина (вход, регистрация, профиль).
 */
enum PageRoutes: string {

	/** Страница авторизации (вход в личный кабинет) */
	case SignIn      = 'sign-in';

	/** Страница регистрации нового пользователя */
	case SignUp      = 'sign-up';

	/** Страница личного кабинета пользователя */
	case UserProfile = 'profile';

	/** Страница согласия на обработку персональных данных */
	case ConsentPage = 'consent';

	/**
	 * Возвращает полный абсолютный URL для текущего маршрута.
	 *
	 * @return string
	 */
	public function url(): string {
		// home_url() — возвращает URL главной страницы сайта
		// esc_url() — экранирует URL для безопасного вывода в HTML
		return esc_url( home_url( '/' . $this->value . '/' ) );
	}

	/**
	 * Проверяет, находится ли пользователь сейчас на этой странице.
	 *
	 * @return bool
	 */
	public function isCurrent(): bool {
		// is_page() — WordPress-функция для проверки текущей страницы по slug/ID
		return is_page( $this->value );
	}
}
