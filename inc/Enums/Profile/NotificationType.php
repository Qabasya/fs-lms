<?php

declare( strict_types=1 );

namespace Inc\Enums\Profile;

/**
 * Каталог типов in-app уведомлений кабинета (V1).
 *
 * Тексты плиток собираются сервером ({@see \Inc\Services\Profile\NotificationService::toClientArray()});
 * клиент по значению этого enum выбирает только иконку ({@see \Inc\Enums\Ui\Icon} зеркало в
 * `src/js/common/icons.js`) и цвет кружка (по {@see self::tone()}).
 */
enum NotificationType: string {

	case VideoUploaded      = 'video_uploaded';
	case DeadlineSoon       = 'deadline_soon';
	case DeadlineMissed     = 'deadline_missed';
	case LessonSoon         = 'lesson_soon';
	case WorkGraded         = 'work_graded';
	case WorkReturned       = 'work_returned';
	case AttemptGraded      = 'attempt_graded';
	case ReviewNeeded       = 'review_needed';
	case SubstituteAssigned = 'substitute_assigned';
	case AttendanceMissed   = 'attendance_missed';
	/** Этап 5 (Tasks.md): урок вне расписания открылся ученикам — плановое занятие
	 * такого не шлёт, за него уже отвечает LessonSoon за 30 минут до начала. */
	case LessonOpened       = 'lesson_opened';
	/** Ученикам группы — при утверждении замены преподавателя (Tasks.md, блок B). */
	case SubstituteAssignedStudent = 'substitute_assigned_student';
	/** Ученикам (+эффективному преподавателю) — при разовой замене кабинета занятия/периода. */
	case RoomChanged        = 'room_changed';

	/** Заголовок плитки уведомления. */
	public function title(): string {
		return match ( $this ) {
			self::VideoUploaded      => 'Появилась запись занятия',
			self::DeadlineSoon       => 'Дедлайн через 24 часа',
			self::DeadlineMissed     => 'Дедлайн пропущен',
			self::LessonSoon         => 'Занятие через 30 минут',
			self::WorkGraded         => 'Работа проверена',
			self::WorkReturned       => 'Работа возвращена на доработку',
			self::AttemptGraded      => 'Экзамен проверен',
			self::ReviewNeeded       => 'Сдана работа — нужна проверка',
			self::SubstituteAssigned => 'Вам назначена замена',
			self::AttendanceMissed   => 'Пропущено занятие',
			self::LessonOpened       => 'Открыт новый урок',
			self::SubstituteAssignedStudent => 'Замена преподавателя',
			self::RoomChanged        => 'Изменился кабинет',
		};
	}

	/** Цвет кружка плитки: ok — позитивное, warn — требует внимания, err — негативное, info — нейтральное. */
	public function tone(): string {
		return match ( $this ) {
			self::VideoUploaded, self::LessonSoon, self::SubstituteAssigned, self::LessonOpened,
			self::SubstituteAssignedStudent, self::RoomChanged => 'info',
			self::WorkGraded, self::AttemptGraded                          => 'ok',
			self::DeadlineSoon, self::WorkReturned, self::ReviewNeeded      => 'warn',
			self::DeadlineMissed, self::AttendanceMissed                   => 'err',
		};
	}
}
