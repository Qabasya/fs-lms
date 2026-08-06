<?php

declare( strict_types=1 );

namespace Inc\Enums\Wp;

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

	/** Шорткод списка видимых уроков ученика */
	case GroupLessons = 'fs_lms_group_lessons';

	/** Шорткод раздела «Тренажёр» лендинга предмета (задания предмета) */
	case SubjectTrainer = 'fs_lms_subject_trainer';

	/** Шорткод раздела «Учебник» лендинга предмета (статьи предмета) */
	case SubjectTextbook = 'fs_lms_subject_textbook';

	/** Шорткод раздела «Курсы» лендинга предмета */
	case SubjectCourses = 'fs_lms_subject_courses';

	/**
	 * Возвращает строку шорткода в формате с квадратными скобками.
	 *
	 * @param array<string, string> $atts Атрибуты шорткода: [имя => значение].
	 *
	 * @return string Например, '[fs_lms_login_form]' или '[fs_lms_subject_trainer subject="math"]'
	 */
	public function tag( array $atts = array() ): string {
		$parts = array( $this->value );

		foreach ( $atts as $name => $value ) {
			$parts[] = sprintf( '%s="%s"', $name, esc_attr( $value ) );
		}

		return '[' . implode( ' ', $parts ) . ']';
	}
}