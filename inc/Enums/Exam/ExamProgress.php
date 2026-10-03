<?php declare( strict_types=1 );

namespace Inc\Enums\Exam;

enum ExamProgress: string {
	case NotStarted = 'not_started';
	case InProgress = 'in_progress';
	case Submitted = 'submitted';
	case Missed = 'missed';

	public function label(): string {
		return match ( $this ) {
			self::NotStarted => 'Не начат',
			self::InProgress => 'В процессе',
			self::Submitted => 'Работа сдана',
			self::Missed => 'Неявка',
		};
	}
}
