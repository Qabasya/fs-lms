<?php

declare( strict_types=1 );

namespace Inc\Enums\Exam;

/**
 * Предметы, по которым назначаются экзамены вне курса («Мои экзамены» в кабинете сотрудника). Значение кейса — ключ предмета.
 *
 * Остальные предметы платформы в экзаменах не участвуют: селекторы кабинета их не показывают, а создание проведения по ним отклоняется
 * ({@see \Inc\Services\Exam\ExamAccessGuard::canManageSubject()}).
 */
enum ExamSubject: string {
	case InfEge = 'inf_ege';
	case InfOge = 'inf_oge';

	/** Ключ предмета входит в список экзаменов. */
	public static function supports( string $subjectKey ): bool {
		return null !== self::tryFrom( $subjectKey );
	}
}
