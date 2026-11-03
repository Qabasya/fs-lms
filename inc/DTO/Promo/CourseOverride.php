<?php
/**
 * Индивидуальное назначение промо-курса.
 *
 * @package Inc\DTO\Promo
 */

declare( strict_types=1 );

namespace Inc\DTO\Promo;

/** Индивидуальное назначение: наследование, явный отзыв или конкретный курс. */
final class CourseOverride {

	/** Создание только через именованные фабрики. */
	private function __construct(
		public readonly bool $inherits,
		public readonly ?int $courseId,
	) {}

	/** Использовать назначение события. */
	public static function inherit(): self {
		return new self( true, null );
	}

	/** Запретить наследование курса события. */
	public static function revoke(): self {
		return new self( false, null );
	}

	/**
	 * Назначить курс только этому участнику.
	 *
	 * @throws \InvalidArgumentException Если ID некорректен.
	 */
	public static function assign( int $courseId ): self {
		if ( $courseId <= 0 ) {
			throw new \InvalidArgumentException( 'Идентификатор курса должен быть положительным.' );
		}

		return new self( false, $courseId );
	}
}
