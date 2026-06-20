<?php

declare( strict_types=1 );

namespace Inc\Services\Import;

use Inc\Enums\Person\DocumentType;

/**
 * Class DocTypeResolver
 *
 * Резолвит колонку «Тип документа» из CSV в значение {@see DocumentType}.
 *
 * ### Правила
 *
 * - Пустая строка → `''` (тип не задан).
 * - Совпало (trim + регистронезависимо) с названием (`label()`), значением
 *   (`pass`/`birth_certificate`/...) или именем case → каноническое значение enum.
 * - Иначе непустой текст → возвращается как есть (отображение сделает fallback
 *   `DocumentType::tryFrom()?->label() ?? raw`).
 */
readonly class DocTypeResolver {

	/**
	 * Резолвит сырое значение типа документа в значение enum.
	 *
	 * @param string $rawType Значение колонки «Тип документа»
	 *
	 * @return string Значение DocumentType или исходный текст
	 */
	public function resolve( string $rawType ): string {
		$trimmed = trim( $rawType );

		if ( '' === $trimmed ) {
			return '';
		}

		$needle = mb_strtolower( $trimmed );

		foreach ( DocumentType::cases() as $case ) {
			$candidates = array(
				mb_strtolower( $case->label() ),
				mb_strtolower( $case->value ),
				mb_strtolower( $case->name ),
			);

			if ( in_array( $needle, $candidates, true ) ) {
				return $case->value;
			}
		}

		return $trimmed;
	}
}
