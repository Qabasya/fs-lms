<?php

namespace Inc\Enums;

enum UserRole: string {
	// == Наши "защищённые" роли / авторизация логин-пароль==
	case FSTeacher = 'lms_teacher'; // наш преподаватель
	case FSStudent = 'lms_student'; // наш ученик
	case FSParent  = 'lms_parent'; // родитель нашего ученика

	// == Роли внешних пользователей / авторизация через провайдеров / без подписки ==
	case Student = 'lms_student_free'; // "левый" ученик
	case Teacher = 'lms_teacher_free'; // "левый" преподаватель

	/**
	 * Возвращает понятное имя роли для WP
	 */
	public function label(): string {
		return match ( $this ) {
			self::FSTeacher => 'Преподаватель',
			self::FSStudent  => 'Ученик',
			self::FSParent  => 'Родитель',
			self::Student   => 'Пользователь',
			self::Teacher   => 'Учитель',
		};
	}
}
