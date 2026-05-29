<?php

declare( strict_types=1 );

namespace Inc\Enums;

/**
 * Enum ShortCode
 *
 * Перечисление шорткодов, используемых в плагине.
 *
 * @package Inc\Enums
 *
 * ### Основные обязанности:
 *
 * 1. **Хранение имён шорткодов** — централизованное хранение идентификаторов шорткодов.
 * 2. **Генерация тега** — создание строки шорткода с квадратными скобками.
 *
 * ### Архитектурная роль:
 *
 * Используется в AuthPageController, ProfileController и PageGeneratorService
 * для единообразной работы со шорткодами плагина (форма входа, регистрации, профиля).
 */
enum ShortCode: string {

	/** Шорткод формы авторизации (входа в личный кабинет) */
	case LoginForm    = 'fs_lms_login_form';

	/** Шорткод формы регистрации нового пользователя */
	case RegisterForm = 'fs_lms_register_form';

	/** Шорткод личного кабинета пользователя */
	case Profile      = 'fs_lms_profile';

	/** Шорткод формы подачи заявки на обучение */
	case ApplyForm    = 'fs_lms_apply_form';

	/**
	 * Возвращает строку шорткода в формате с квадратными скобками.
	 *
	 * @return string Например, '[fs_lms_login_form]'
	 */
	public function tag(): string {
		return '[' . $this->value . ']';
	}
}