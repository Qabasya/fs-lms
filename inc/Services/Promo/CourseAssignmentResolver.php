<?php
/**
 * Определение промо-курса участника.
 *
 * @package Inc\Services\Promo
 */

declare( strict_types=1 );

namespace Inc\Services\Promo;

use Inc\DTO\Promo\CourseOverride;

/** Определяет назначение события с учётом индивидуального исключения. Не выдаёт доступ. */
final class CourseAssignmentResolver {

	/**
	 * Возвращает курс или null при отсутствии назначения либо явном отзыве.
	 *
	 * @throws \InvalidArgumentException Если ID курса события некорректен.
	 */
	public function resolve( ?int $eventCourseId, CourseOverride $override ): ?int {
		if ( null !== $eventCourseId && $eventCourseId <= 0 ) {
			throw new \InvalidArgumentException( 'Идентификатор курса должен быть положительным.' );
		}

		return $override->inherits ? $eventCourseId : $override->courseId;
	}
}
