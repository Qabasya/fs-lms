<?php

namespace Inc\Enums;

enum URL: string {
	case SIGN_IN      = 'sign-in'; // вход
	case SIGN_UP      = 'sign-up'; // регистрация
	case USER_PROFILE = 'profile'; // личный кабинет

	/**
	 * Получить полный абсолютный URL для текущего маршрута
	 * * @return string
	 */
	public function url(): string {
		// home_url() автоматически добавит правильный домен (localhost или боевой)
		return esc_url( home_url( '/' . $this->value . '/' ) );
	}

	/**
	 * Проверить, находится ли пользователь сейчас на этой странице
	 * * @return bool
	 */
	public function isCurrent(): bool {
		return is_page( $this->value );
	}

}
