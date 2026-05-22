<?php

declare( strict_types=1 );

namespace Inc\Shared\Traits;

trait SlugGenerator {

	/**
	 * Транслитерирует и санитизирует строку в WP-совместимый слаг.
	 * Порядок: кириллица → латиница → sanitize_title → fallback.
	 *
	 * @param string $input    Исходная строка (может содержать кириллицу, латиницу, символы)
	 * @param string $fallback Значение, если после санитизации получилась пустая строка
	 *
	 * @return string
	 */
	protected function slugify( string $input, string $fallback = 'item' ): string {
		$slug = sanitize_title( $this->transliterate( trim( $input ) ) );

		return $slug !== '' ? $slug : sanitize_key( $fallback );
	}

	/**
	 * Проверяет, является ли строка валидным WP-слагом.
	 * Допустимый формат: только строчные латинские буквы, цифры и дефисы.
	 *
	 * @param string $slug
	 *
	 * @return bool
	 */
	protected function isValidSlug( string $slug ): bool {
		return $slug !== '' && (bool) preg_match( '/^[a-z0-9][a-z0-9\-]*$/', $slug );
	}

	/**
	 * Транслитерирует кириллическую строку в латинскую.
	 *
	 * @param string $string Исходная строка
	 *
	 * @return string
	 */
	private function transliterate( string $string ): string {
		$map = array(
			'а' => 'a',    'б' => 'b',    'в' => 'v',    'г' => 'g',    'д' => 'd',
			'е' => 'e',    'ё' => 'yo',   'ж' => 'zh',   'з' => 'z',    'и' => 'i',
			'й' => 'y',    'к' => 'k',    'л' => 'l',    'м' => 'm',    'н' => 'n',
			'о' => 'o',    'п' => 'p',    'р' => 'r',    'с' => 's',    'т' => 't',
			'у' => 'u',    'ф' => 'f',    'х' => 'kh',   'ц' => 'ts',   'ч' => 'ch',
			'ш' => 'sh',   'щ' => 'shch', 'ь' => '',     'ы' => 'y',    'ъ' => '',
			'э' => 'e',    'ю' => 'yu',   'я' => 'ya',

			'А' => 'A',    'Б' => 'B',    'В' => 'V',    'Г' => 'G',    'Д' => 'D',
			'Е' => 'E',    'Ё' => 'Yo',   'Ж' => 'Zh',   'З' => 'Z',    'И' => 'I',
			'Й' => 'Y',    'К' => 'K',    'Л' => 'L',    'М' => 'M',    'Н' => 'N',
			'О' => 'O',    'П' => 'P',    'Р' => 'R',    'С' => 'S',    'Т' => 'T',
			'У' => 'U',    'Ф' => 'F',    'Х' => 'Kh',   'Ц' => 'Ts',   'Ч' => 'Ch',
			'Ш' => 'Sh',   'Щ' => 'Shch', 'Ь' => '',     'Ы' => 'Y',    'Ъ' => '',
			'Э' => 'E',    'Ю' => 'Yu',   'Я' => 'Ya',
		);

		return strtr( $string, $map );
	}
}
