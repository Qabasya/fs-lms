<?php

declare( strict_types=1 );

namespace Inc\Enums\Log;

/**
 * Экраны кабинета ученика и родителя, открытие которых пишется в ленту «Активность».
 *
 * Значение — ключ экрана в `window.fsProfile` без префикса роли (`student-lessons` → `lessons`);
 * клиент присылает только его, сервер принимает лишь кейсы этого энума.
 */
enum TrackedPage: string {

	case Home       = 'home';
	case Lessons    = 'lessons';
	case Exams      = 'exams';
	case Grades     = 'grades';
	case Attendance = 'attendance';

	public function label(): string {
		return match ( $this ) {
			self::Home       => 'Главная',
			self::Lessons    => 'Мои уроки',
			self::Exams      => 'Экзамены',
			self::Grades     => 'Оценки',
			self::Attendance => 'Посещаемость',
		};
	}
}
