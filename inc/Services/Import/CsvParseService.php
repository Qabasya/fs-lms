<?php

declare( strict_types=1 );

namespace Inc\Services\Import;

use InvalidArgumentException;
use RuntimeException;

/**
 * Class CsvParseService
 *
 * Механизм чтения CSV для импорта: ничего не знает о доменных сущностях.
 *
 * ### Обязанности
 *
 * 1. **Чтение** — потоковый разбор файла построчно (без загрузки целиком в память).
 * 2. **Нормализация** — срез UTF-8 BOM, авто-детект разделителя (`;`/`,`),
 *    перекодировка cp1251 (Windows-1251) → UTF-8.
 * 3. **Маппинг** — каждая строка данных возвращается как ассоц-массив
 *    «заголовок → значение».
 *
 * Валидацию набора колонок выполняет validateHeaders(): вызывающий код
 * получает фактические заголовки как ключи первой строки генератора.
 */
class CsvParseService {

	/**
	 * Потоково разбирает CSV-файл.
	 *
	 * @param string $filePath Путь к файлу (обычно $_FILES['file']['tmp_name'])
	 *
	 * @return iterable<array<string, string>> Генератор строк «заголовок → значение»
	 *
	 * @throws RuntimeException Если файл не удалось открыть
	 */
	public function parse( string $filePath ): iterable {
		$handle = fopen( $filePath, 'r' );
		if ( false === $handle ) {
			throw new RuntimeException( 'Не удалось открыть файл импорта.' );
		}

		$delimiter = $this->detectDelimiter( $filePath );
		$headers   = null;

		try {
			while ( false !== ( $cells = fgetcsv( $handle, 0, $delimiter, '"', '' ) ) ) {
				// Пропуск полностью пустых строк
				if ( array( null ) === $cells || ( 1 === count( $cells ) && null === $cells[0] ) ) {
					continue;
				}

				$cells = array_map(
					fn( $value ): string => $this->normalizeEncoding( (string) ( $value ?? '' ) ),
					$cells
				);

				if ( null === $headers ) {
					$cells[0] = $this->stripBom( $cells[0] );
					$headers  = array_map( 'trim', $cells );
					continue;
				}

				// Выравнивание длины строки под количество заголовков
				$count = count( $headers );
				$cells = array_pad( array_slice( $cells, 0, $count ), $count, '' );

				yield array_combine( $headers, $cells );
			}
		} finally {
			fclose( $handle );
		}
	}

	/**
	 * Проверяет, что в файле присутствуют все обязательные колонки.
	 *
	 * @param string[] $expected Ожидаемые заголовки
	 * @param string[] $actual   Фактические заголовки (ключи первой строки parse())
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException Если каких-то колонок не хватает
	 */
	public function validateHeaders( array $expected, array $actual ): void {
		$normalize = static fn( array $list ): array => array_map( 'trim', $list );

		$missing = array_diff( $normalize( $expected ), $normalize( $actual ) );

		if ( ! empty( $missing ) ) {
			throw new InvalidArgumentException(
				'В файле отсутствуют обязательные колонки: ' . implode( ', ', $missing )
			);
		}
	}

	/**
	 * Определяет разделитель по первой строке файла.
	 *
	 * @param string $filePath Путь к файлу
	 *
	 * @return string `;` или `,`
	 */
	private function detectDelimiter( string $filePath ): string {
		$firstLine = '';
		$handle    = fopen( $filePath, 'r' );

		if ( false !== $handle ) {
			$firstLine = (string) fgets( $handle );
			fclose( $handle );
		}

		return substr_count( $firstLine, ';' ) >= substr_count( $firstLine, ',' ) ? ';' : ',';
	}

	/**
	 * Приводит значение к UTF-8 (из cp1251, если строка не валидный UTF-8).
	 *
	 * @param string $value Сырое значение ячейки
	 *
	 * @return string
	 */
	private function normalizeEncoding( string $value ): string {
		if ( '' === $value || mb_check_encoding( $value, 'UTF-8' ) ) {
			return $value;
		}

		return mb_convert_encoding( $value, 'UTF-8', 'Windows-1251' );
	}

	/**
	 * Срезает UTF-8 BOM в начале строки.
	 *
	 * @param string $value Значение первой ячейки заголовка
	 *
	 * @return string
	 */
	private function stripBom( string $value ): string {
		return preg_replace( '/^\xEF\xBB\xBF/', '', $value ) ?? $value;
	}
}
