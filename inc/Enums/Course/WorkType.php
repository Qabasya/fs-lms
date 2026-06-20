<?php

declare( strict_types=1 );

namespace Inc\Enums\Course;

/**
 * Тип работы (набора заданий) урока.
 *
 * Тип живёт на самой работе (CPT {key}_works); сдача (submissions.work_type)
 * снапшотится из работы.
 *
 * @package Inc\Enums
 */
enum WorkType: string {

	/** Практика (классная работа). */
	case Practice = 'practice';

	/** Самостоятельная работа. */
	case Independent = 'independent';

	/** Домашнее задание. */
	case Homework = 'homework';

	/**
	 * Человекочитаемое название типа работы.
	 *
	 * @return string
	 */
	public function label(): string {
		return match ( $this ) {
			self::Practice    => 'Практика',
			self::Independent => 'Самостоятельная работа',
			self::Homework    => 'Домашнее задание',
		};
	}

	/**
	 * Безопасно приводит произвольную строку к валидному типу работы.
	 *
	 * @param string $value Сырое значение.
	 *
	 * @return self Тип работы (по умолчанию — Practice).
	 */
	public static function fromValueOrDefault( string $value ): self {
		return self::tryFrom( $value ) ?? self::Practice;
	}

	/**
	 * Карта значений для рендера select: value => label.
	 *
	 * @return array<string, string>
	 */
	public static function options(): array {
		$options = array();
		foreach ( self::cases() as $case ) {
			$options[ $case->value ] = $case->label();
		}

		return $options;
	}
}
