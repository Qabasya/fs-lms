<?php

declare( strict_types=1 );

namespace Inc\Enums\Course;

/**
 * Карточка-заглушка блока «Курсы» в сайдбаре тренажёра, задания и статьи.
 *
 * Реальные курсы в сайдбар не выводятся: вместо них — программа подготовки
 * направления, ссылка «Подробнее» ведёт на страницу предмета. Значение кейса —
 * ключ предмета; предмета нет в списке — блока нет.
 *
 * @package Inc\Enums\Course
 */
enum SidebarCoursePromo: string {
	case InfEge = 'inf_ege';
	case InfOge = 'inf_oge';

	/** Уроков в программе подготовки. */
	public const int LESSONS = 72;

	public function title(): string {
		return match ( $this ) {
			self::InfEge => 'Подготовка к ЕГЭ по информатике',
			self::InfOge => 'Подготовка к ОГЭ по информатике',
		};
	}
}
