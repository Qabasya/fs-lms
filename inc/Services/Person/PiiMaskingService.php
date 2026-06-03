<?php

declare( strict_types=1 );

namespace Inc\Services\Person;

use Inc\Enums\PiiField;

/**
 * Class PiiMaskingService
 *
 * Сервис для маскирования персональных данных (PII) перед выводом в UI.
 * Обеспечивает безопасность данных в соответствии с требованиями 152-ФЗ.
 *
 * @package Inc\Services
 */
class PiiMaskingService {
	/**
	 * Маскирует одиночное значение на основе типа поля PII.
	 *
	 * @param string   $value Значение, которое необходимо замаскировать.
	 * @param PiiField $type  Тип поля PII.
	 * @return string Маскированная строка.
	 */
	public function mask( string $value, PiiField $type ): string {
		if ( PiiField::Password === $type ) {
			return $this->maskPassword();
		}

		$trimmed = trim( $value );
		if ( '' === $trimmed ) {
			return '';
		}

		return match ( $type ) {
			PiiField::FullName  => $trimmed,
			PiiField::Pass      => $this->maskPass( $trimmed ),
			PiiField::Inn       => $this->maskInn( $trimmed ),
			PiiField::Phone     => $this->maskPhone( $trimmed ),
			PiiField::Address   => $this->maskAddress( $trimmed ),
			PiiField::Password  => $this->maskPassword(),
		};
	}

	/**
	 * Пакетная обработка массива значений.
	 *
	 * @param array<string, string> $values Ассоциативный массив "ключ => сырое значение"
	 * @param array<string, mixed>  $types  Ассоциативный массив "ключ => PiiField enum"
	 * @return array<string, string> Маскированный ассоциативный массив.
	 */
	public function maskBulk( array $values, array $types ): array {
		$result = array();

		foreach ( $values as $key => $value ) {
			if ( isset( $types[ $key ] ) && $types[ $key ] instanceof PiiField ) {
				$result[ $key ] = $this->mask( (string) $value, $types[ $key ] );
			} else {
				$result[ $key ] = (string) $value;
			}
		}

		return $result;
	}

	/**
	 * Возвращает статическую маску для пароля независимо от значения.
	 */
	public function maskedPasswordPlaceholder(): string {
		return '••••••••';
	}

	// ============================ ПРИВАТНЫЕ МЕТОДЫ ============================ //
	/**
	 * Паспорт: оставить первые 2 цифры (серия) и последние 2 цифры (номер).
	 * Вся середина заменяется шаблоном по формату: 12 •• ••••34
	 *
	 * @param string $value Сырое значение паспорта.
	 * @return string Маскированная строка.
	 */
	private function maskPass( string $value ): string {
		// Очищаем от любых пробелов
		$clean  = (string) preg_replace( '/\s+/', '', $value );
		$length = mb_strlen( $clean );

		// Если длина строки позволяет выделить первые 2 и последние 2 символа
		if ( $length >= 4 ) {
			$start = mb_substr( $clean, 0, 2 );
			$end   = mb_substr( $clean, -2 );

			return $start . ' •• ••••' . $end;
		}

		// На случай пограничного кейса с некорректным заполнением
		if ( 0 !== $length ) {
			return '•• •• •••••' . mb_substr( $clean, -1 );
		}

		return '•• •• ••••••';
	}

	/**
	 * ИНН: оставить открытыми ТОЛЬКО последние 4 цифры.
	 * Формат вывода: •••• •••• 1234
	 *
	 * @param string $value Сырое значение ИНН.
	 * @return string Маскированная строка.
	 */
	private function maskInn( string $value ): string {
		// Очищаем от пробелов, дефисов и любых не-цифровых символов
		$digits = (string) preg_replace( '/\D/', '', $value );
		$length = strlen( $digits );

		// Если цифр достаточно для выделения последних 4 знаков
		if ( $length >= 4 ) {
			$end = substr( $digits, -4 );
			return '•••• •••• ' . $end;
		}

		// На случай пограничного кейса с некорректным заполнением
		if ( 0 !== $length ) {
			return '•••• •••• ' . $digits;
		}

		return '•••• •••• ••••';
	}
	/**
	 * Телефон: маскирование номера, прилетающего без кода страны.
	 * Отображает открытыми ТОЛЬКО последние 4 цифры в формате: ••• ••• 12 34
	 *
	 * @param string $value Сырое значение телефона с фронтенда.
	 * @return string Маскированная строка.
	 */
	private function maskPhone( string $value ): string {
		// Очищаем от всего, кроме цифр
		$digits = (string) preg_replace( '/\D/', '', $value );
		$length = strlen( $digits );

		// Если цифр достаточно для выделения последних 4 знаков
		if ( $length >= 4 ) {
			$part3 = substr( $digits, -4, 2 ); // предпоследние две цифры
			$part4 = substr( $digits, -2 );    // последние две цифры

			return '••• ••• ' . $part3 . ' ' . $part4;
		}

		// На случай пограничного кейса, если прилетело меньше 4 цифр
		if ( 0 !== $length ) {
			return '••• ••• •• ' . $digits;
		}

		return '••• ••• •• ••';
	}

	private function maskPassword(): string {
		return '••••••••';
	}

	/**
	 * Адрес: оставить только название города, остальное заменить на ••••••
	 * Пример: "г. Москва, ул. Ленина, д. 5" -> "г. Москва, ••••••"
	 */
	private function maskAddress( string $value ): string {
		// Ищем совпадение до первой запятой (обычно это город/населенный пункт)
		$parts = explode( ',', $value );
		if ( count( $parts ) > 1 ) {
			return trim( $parts[0] ) . ', ••••••';
		}

		// Если запятых нет, но есть "г. Название"
		if ( 1 === preg_match( '/^(г\.\s*[А-Яа-яA-Za-z\-]+)/u', $value, $matches ) ) {
			return $matches[1] . ', ••••••';
		}

		return 'г. ••••••, ••••••';
	}

}
