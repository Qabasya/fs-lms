<?php

declare( strict_types=1 );

namespace Inc\Services\Import;

use Inc\Contracts\RowImporterInterface;
use DateTime;
use InvalidArgumentException;

/**
 * Class AbstractRowImporter
 *
 * Общее для импортёров строки CSV: проверка обязательных значений и разбор дат
 * в человеческих форматах.
 *
 * @package Inc\Services\Import
 *
 * Домен строки (какие колонки читать, что создавать, где граница транзакции)
 * остаётся у наследника — {@see StudentRowImporter} и {@see EnrolledStudentRowImporter}.
 */
abstract readonly class AbstractRowImporter implements RowImporterInterface {

	/** Поддерживаемые форматы дат в CSV (Y-m-d — канонический). */
	private const DATE_FORMATS = array( 'Y-m-d', 'd.m.Y', 'd/m/Y', 'd-m-Y' );

	/**
	 * Бросает исключение, если какое-то обязательное значение пустое.
	 *
	 * @param array<string, string> $values [колонка => значение]
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException
	 */
	protected function requireValues( array $values ): void {
		foreach ( $values as $label => $value ) {
			if ( '' === $value ) {
				throw new InvalidArgumentException( "Не заполнена обязательная колонка «{$label}»." );
			}
		}
	}

	/**
	 * Нормализует дату в формат Y-m-d.
	 *
	 * @param string $value Дата в формате Y-m-d / d.m.Y / d/m/Y / d-m-Y
	 *
	 * @return string|null Y-m-d или null
	 */
	protected function toDate( string $value ): ?string {
		$value = trim( $value );
		if ( '' === $value ) {
			return null;
		}

		foreach ( self::DATE_FORMATS as $format ) {
			$date   = DateTime::createFromFormat( '!' . $format, $value );
			$errors = DateTime::getLastErrors();
			$clean  = false === $errors || ( 0 === $errors['warning_count'] && 0 === $errors['error_count'] );

			if ( $date instanceof DateTime && $clean ) {
				return $date->format( 'Y-m-d' );
			}
		}

		$timestamp = strtotime( $value );

		return false !== $timestamp ? gmdate( 'Y-m-d', $timestamp ) : null;
	}

	/**
	 * Нормализует дату в datetime (полночь) для колонок типа datetime.
	 *
	 * @param string $value Дата
	 *
	 * @return string|null Y-m-d 00:00:00 или null
	 */
	protected function toDateTime( string $value ): ?string {
		$date = $this->toDate( $value );

		return null === $date ? null : $date . ' 00:00:00';
	}
}
