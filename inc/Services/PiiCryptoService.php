<?php

/**
 * Сервис шифрования персональных данных (PII)
 *
 * @package Inc
 * @subpackage Services
 * @since 1.0.0
 */

declare(strict_types=1);

namespace Inc\Services;

use RuntimeException;

/**
 * Class PiiCryptoService
 *
 * Сервис для шифрования и хеширования персональных данных (PII).
 * Использует libsodium (XSalsa20-Poly1305) для шифрования и SHA-256 для хеширования.
 *
 * @package Inc\Services
 *
 * ### Основные обязанности:
 *
 * 1. **Шифрование данных** — защита PII (ФИО, паспорт, ИНН, адрес, телефон).
 * 2. **Расшифровка данных** — получение исходных данных при наличии прав доступа.
 * 3. **Хеширование для поиска** — создание детерминированных хешей для поиска без расшифровки.
 *
 * ### Архитектурная роль:
 *
 * Используется во всех сервисах, работающих с персональными данными
 * (ApplicationService, EnrollmentService, PersonService, ConsentService).
 * Обеспечивает compliance с 152-ФЗ за счёт шифрования PII.
 *
 * ### Требования к окружению:
 *
 * - PHP 7.2+ с расширением libsodium (sodium)
 * - Константы в wp-config.php:
 *   - FS_LMS_ENC_KEY — ключ шифрования (base64, 32 байта)
 *   - FS_LMS_HASH_SALT — соль для хеширования
 *
 * ### Формат зашифрованных данных:
 *
 * [nonce (24 байта)] + [ciphertext (зашифрованный текст + MAC)]
 * Общая длина зависит от исходных данных.
 * Данные хранятся в колонках типа BLOB (бинарные).
 */
class PiiCryptoService {

	/**
	 * Ключ шифрования (32 байта)
	 *
	 * @var string
	 */
	private string $key;

	/**
	 * Соль для хеширования
	 *
	 * @var string
	 */
	private string $hashSalt;

	/**
	 * Конструктор класса.
	 *
	 * Читает конфигурационные константы из wp-config.php,
	 * валидирует их и инициализирует криптографические параметры.
	 *
	 * @throws RuntimeException Если ключ шифрования отсутствует или имеет неверную длину.
	 * @throws RuntimeException Если соль для хеширования отсутствует или пуста.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		// Проверка наличия ключа шифрования
		if ( ! defined( 'FS_LMS_ENC_KEY' ) ) {
			throw new RuntimeException( 'Константа FS_LMS_ENC_KEY не определена в wp-config.php' );
		}

		// base64_decode() — декодирование ключа из base64
		$decodedKey = base64_decode( FS_LMS_ENC_KEY, true );

		// SODIUM_CRYPTO_SECRETBOX_KEYBYTES — константа libsodium (32 байта)
		if ( false === $decodedKey || strlen( $decodedKey ) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) {
			throw new RuntimeException(
				sprintf(
					'Неверная длина ключа шифрования. Ожидается %d байт',
					SODIUM_CRYPTO_SECRETBOX_KEYBYTES
				)
			);
		}

		$this->key = $decodedKey;

		// Проверка наличия соли для хеширования
		if ( ! defined( 'FS_LMS_HASH_SALT' ) || '' === FS_LMS_HASH_SALT ) {
			throw new RuntimeException( 'Константа FS_LMS_HASH_SALT не определена или пуста в wp-config.php' );
		}

		$this->hashSalt = FS_LMS_HASH_SALT;
	}

	/**
	 * Шифрует строку с использованием алгоритма XSalsa20-Poly1305.
	 *
	 * Генерирует случайный nonce, шифрует данные и возвращает
	 * конкатенацию nonce || ciphertext в виде бинарной строки.
	 *
	 * ⚠️ Возвращаемое значение — бинарные данные (не UTF-8).
	 * Для хранения в БД используйте колонку типа BLOB.
	 *
	 * @param string $plaintext Исходная строка для шифрования
	 *
	 * @return string Бинарная строка (nonce + зашифрованные данные)
	 *
	 * @since 1.0.0
	 */
	public function encrypt( string $plaintext ): string {
		// random_bytes() — генерация криптографически безопасного nonce
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		// sodium_crypto_secretbox() — шифрование с аутентификацией
		$ciphertext = sodium_crypto_secretbox( $plaintext, $nonce, $this->key );

		// Конкатенация nonce и ciphertext для хранения в одном BLOB
		return $nonce . $ciphertext;
	}

	/**
	 * Расшифровывает бинарный blob, полученный из метода encrypt().
	 *
	 * Извлекает nonce из начала строки, расшифровывает данные.
	 * При ошибке расшифровки (повреждённые данные или неверный ключ)
	 * выбрасывает исключение.
	 *
	 * @param string $blob Бинарная строка (nonce + ciphertext)
	 *
	 * @return string Расшифрованная строка
	 *
	 * @throws RuntimeException Если данные повреждены или ключ неверен
	 *
	 * @since 1.0.0
	 */
	public function decrypt( string $blob ): string {
		$nonceLength = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
		// Минимальная длина: nonce + MAC (аутентификационный тег)
		$minLength   = $nonceLength + SODIUM_CRYPTO_SECRETBOX_MACBYTES;

		// Проверка минимальной длины (защита от повреждённых данных)
		if ( strlen( $blob ) < $minLength ) {
			throw new RuntimeException( 'Неверный формат зашифрованных данных' );
		}

		// substr() — извлечение nonce и ciphertext
		$nonce      = substr( $blob, 0, $nonceLength );
		$ciphertext = substr( $blob, $nonceLength );

		// sodium_crypto_secretbox_open() — расшифровка с проверкой MAC
		$plaintext = sodium_crypto_secretbox_open( $ciphertext, $nonce, $this->key );

		if ( false === $plaintext ) {
			throw new RuntimeException( 'Ошибка расшифровки: данные повреждены или ключ неверен' );
		}

		return $plaintext;
	}

	/**
	 * Вычисляет детерминированный хеш строки для поиска дубликатов.
	 *
	 * Нормализует входное значение (приводит к нижнему регистру,
	 * удаляет пробелы по краям) и вычисляет SHA-256 хеш с добавлением соли.
	 *
	 * Используется для хранения searchable-хешей ПД (паспорт, ИНН, телефон, ФИО)
	 * без возможности восстановления исходного значения.
	 *
	 * @param string $value Исходное значение для хеширования
	 *
	 * @return string Шестнадцатеричная строка хеша (64 символа)
	 *
	 * @since 1.0.0
	 */
	public function hash( string $value ): string {
		// mb_strtolower() — приведение к нижнему регистру (UTF-8)
		// trim() — удаление пробелов по краям
		$normalized = mb_strtolower( trim( $value ) );

		// hash() — SHA-256 хеш с добавлением соли (защита от rainbow tables)
		return hash( 'sha256', $normalized . $this->hashSalt );
	}

	/**
	 * Проверяет доступность и валидность конфигурации шифрования.
	 *
	 * Статический метод для проверки до инстанциации класса.
	 * Не выбрасывает исключения — возвращает булево значение.
	 *
	 * @return bool true если конфигурация валидна, false иначе
	 *
	 * @since 1.0.0
	 */
	public static function isAvailable(): bool {
		if ( ! defined( 'FS_LMS_ENC_KEY' ) ) {
			return false;
		}

		$decodedKey = base64_decode( FS_LMS_ENC_KEY, true );

		if ( false === $decodedKey || strlen( $decodedKey ) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) {
			return false;
		}

		if ( ! defined( 'FS_LMS_HASH_SALT' ) || '' === FS_LMS_HASH_SALT ) {
			return false;
		}

		return true;
	}
}