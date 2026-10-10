<?php

declare( strict_types=1 );

namespace Inc\Enums\Profile;

/**
 * Экраны кабинета ученика и родителя. Ключ экрана — часть адреса
 * (`/profile/?screen=student-grades`), у ученика префикс `student-`, у родителя
 * `parent-`: набор экранов один, а адрес говорит, чей это кабинет.
 *
 * Сервер может выдать ссылку любого префикса: кабинет (`app.js`) сам приводит
 * её к ключу роли вошедшего, поэтому уведомление, ушедшее и ученику, и родителю,
 * ведёт каждого на его экран. Старые ссылки `learner-*` из сохранённых уведомлений
 * работают так же.
 */
enum LearnerScreen: string {

	case Home       = 'home';
	case Lessons    = 'lessons';
	case Grades     = 'grades';
	case Attendance = 'attendance';

	public const PREFIX_STUDENT = 'student';
	public const PREFIX_PARENT  = 'parent';

	/** Ключ экрана для ученика (по умолчанию) или родителя. */
	public function key( bool $forParent = false ): string {
		return ( $forParent ? self::PREFIX_PARENT : self::PREFIX_STUDENT ) . '-' . $this->value;
	}

	public function label(): string {
		return match ( $this ) {
			self::Home       => 'Главная',
			self::Lessons    => 'Мои курсы',
			self::Grades     => 'Мои оценки',
			self::Attendance => 'Посещаемость',
		};
	}
}
