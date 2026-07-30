<?php

declare( strict_types=1 );

namespace Inc\Services\Subject\Bundle;

use RuntimeException;
use ZipArchive;

/**
 * Class BundleArchive
 *
 * Запись и чтение ZIP-пакета переноса предмета.
 *
 * @package Inc\Services\Subject\Bundle
 *
 * ### Безопасность распаковки (Этап 7)
 *
 * Вход — файл, загруженный пользователем, поэтому распаковка обязана считать
 * содержимое архива враждебным:
 *
 * - **zip-slip / path traversal** — имя записи внутри ZIP может содержать
 *   `../` или абсолютный путь и увести запись за пределы каталога распаковки
 *   (вплоть до перезаписи файлов WordPress). Каждое имя проверяется до
 *   извлечения; архив с хотя бы одной подозрительной записью отвергается
 *   целиком, а не «частично распаковывается».
 * - **Символьные ссылки и вложенные каталоги** — допускается ровно два вида
 *   записей: корневой `manifest.json` и файлы внутри `media/`.
 * - **Zip-бомбы** — суммарный распакованный объём ограничен.
 *
 * ### Целостность
 *
 * Каждый медиафайл в манифесте несёт `sha256`. Проверка идёт сразу после
 * распаковки и ДО любой записи в БД: повреждённый архив обязан упасть раньше,
 * чем создаст половину предмета.
 */
class BundleArchive {

	/**
	 * Максимальный суммарный размер распакованного пакета (защита от zip-бомбы).
	 */
	private const int MAX_UNPACKED_BYTES = 512 * 1024 * 1024;

	/**
	 * Пакует манифест и медиафайлы в ZIP.
	 *
	 * @param array                                                    $manifest Манифест пакета
	 * @param array<int, array{path: string, archive_path: string}>     $files    Файлы: путь на диске → путь в архиве
	 * @param string                                                   $target   Куда писать ZIP
	 *
	 * @return void
	 *
	 * @throws RuntimeException При ошибке создания архива
	 */
	public function write( array $manifest, array $files, string $target ): void {
		$zip    = new ZipArchive();
		$opened = $zip->open( $target, ZipArchive::CREATE | ZipArchive::OVERWRITE );

		if ( true !== $opened ) {
			throw new RuntimeException( 'Не удалось создать архив пакета (код ' . $opened . ').' );
		}

		$json = wp_json_encode( $manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
		if ( false === $json ) {
			$zip->close();
			throw new RuntimeException( 'Не удалось сериализовать манифест пакета.' );
		}

		$zip->addFromString( BundleSchema::MANIFEST, $json );

		foreach ( $files as $file ) {
			if ( is_readable( $file['path'] ) ) {
				$zip->addFile( $file['path'], $file['archive_path'] );
			}
		}

		$zip->close();
	}

	/**
	 * Распаковывает архив во временный каталог и возвращает манифест.
	 *
	 * @param string $archivePath Путь к загруженному ZIP
	 * @param string $extractDir  Каталог распаковки (создаётся вызывающим кодом)
	 *
	 * @return array Декодированный манифест
	 *
	 * @throws RuntimeException При небезопасном или повреждённом архиве
	 */
	public function read( string $archivePath, string $extractDir ): array {
		$zip    = new ZipArchive();
		$opened = $zip->open( $archivePath );

		if ( true !== $opened ) {
			throw new RuntimeException( 'Не удалось открыть архив пакета — файл повреждён или не является ZIP.' );
		}

		try {
			$this->assertSafeEntries( $zip );

			if ( ! $zip->extractTo( $extractDir ) ) {
				throw new RuntimeException( 'Не удалось распаковать архив пакета.' );
			}
		} finally {
			$zip->close();
		}

		$manifestPath = rtrim( $extractDir, '/\\' ) . DIRECTORY_SEPARATOR . BundleSchema::MANIFEST;
		if ( ! is_readable( $manifestPath ) ) {
			throw new RuntimeException( 'В архиве нет файла ' . BundleSchema::MANIFEST . '.' );
		}

		$manifest = json_decode( (string) file_get_contents( $manifestPath ), true );
		if ( ! is_array( $manifest ) ) {
			throw new RuntimeException( 'Файл ' . BundleSchema::MANIFEST . ' повреждён — не удалось разобрать JSON.' );
		}

		return $manifest;
	}

	/**
	 * Проверяет целостность распакованных медиафайлов по sha256 из манифеста.
	 *
	 * @param array  $media      Раздел `media[]` манифеста
	 * @param string $extractDir Каталог распаковки
	 *
	 * @return void
	 *
	 * @throws RuntimeException При несовпадении контрольной суммы
	 */
	public function verifyChecksums( array $media, string $extractDir ): void {
		foreach ( $media as $entry ) {
			$relative = (string) ( $entry['file'] ?? '' );
			$expected = (string) ( $entry['sha256'] ?? '' );

			if ( '' === $relative || '' === $expected ) {
				continue;
			}

			$path = rtrim( $extractDir, '/\\' ) . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative );
			if ( ! is_readable( $path ) ) {
				throw new RuntimeException( "Архив неполный: отсутствует медиафайл «{$relative}»." );
			}

			if ( ! hash_equals( $expected, (string) hash_file( 'sha256', $path ) ) ) {
				throw new RuntimeException( "Повреждён медиафайл «{$relative}»: контрольная сумма не совпадает." );
			}
		}
	}

	/**
	 * Отвергает архив с небезопасными или посторонними записями.
	 *
	 * @param ZipArchive $zip Открытый архив
	 *
	 * @return void
	 *
	 * @throws RuntimeException При подозрительной записи
	 */
	private function assertSafeEntries( ZipArchive $zip ): void {
		$totalSize = 0;

		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$stat = $zip->statIndex( $i );
			if ( false === $stat ) {
				throw new RuntimeException( 'Архив пакета повреждён — не читается оглавление.' );
			}

			$name       = (string) $stat['name'];
			$totalSize += (int) ( $stat['size'] ?? 0 );

			if ( $totalSize > self::MAX_UNPACKED_BYTES ) {
				throw new RuntimeException( 'Распакованный размер пакета превышает допустимый лимит.' );
			}

			// Каталоги внутри `media/` — единственная допустимая «папочная» запись.
			if ( str_ends_with( $name, '/' ) ) {
				if ( BundleSchema::MEDIA_DIR !== $name ) {
					throw new RuntimeException( "Архив содержит посторонний каталог «{$name}»." );
				}
				continue;
			}

			$this->assertSafePath( $name );

			$isManifest = BundleSchema::MANIFEST === $name;
			$isMedia    = str_starts_with( $name, BundleSchema::MEDIA_DIR );

			if ( ! $isManifest && ! $isMedia ) {
				throw new RuntimeException( "Архив содержит посторонний файл «{$name}»." );
			}
		}
	}

	/**
	 * Проверяет имя записи на выход за пределы каталога распаковки.
	 *
	 * Проверяется само имя из оглавления ZIP, а не результат распаковки:
	 * к моменту, когда файл окажется на диске, вред уже нанесён.
	 *
	 * @param string $name Имя записи внутри архива
	 *
	 * @return void
	 *
	 * @throws RuntimeException При попытке path traversal
	 */
	private function assertSafePath( string $name ): void {
		$normalized = str_replace( '\\', '/', $name );

		$isUnsafe = '' === $normalized
			|| str_starts_with( $normalized, '/' )                 // абсолютный путь
			|| preg_match( '#^[a-zA-Z]:/#', $normalized ) === 1     // windows-диск
			|| str_contains( $normalized, "\0" )                    // обрыв строки
			|| in_array( '..', explode( '/', $normalized ), true );  // выход вверх

		if ( $isUnsafe ) {
			throw new RuntimeException( "Архив отклонён: небезопасный путь «{$name}» внутри пакета." );
		}
	}
}
