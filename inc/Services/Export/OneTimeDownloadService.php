<?php

declare( strict_types=1 );

namespace Inc\Services\Export;

/**
 * Class OneTimeDownloadService
 *
 * Одноразовые ссылки на скачивание сгенерированных файлов.
 *
 * @package Inc\Services\Export
 *
 * ### Как работает
 *
 * Файл кладётся в `uploads/lms-exports/`, а в транзиент пишется его путь,
 * имя и MIME. Ссылка вида `/lms/export/{token}` обслуживается
 * {@see \Inc\Controllers\Person\PiiController::handleExportDownload()}, который
 * после первой отдачи удаляет и транзиент, и файл.
 *
 * ### Зачем выделено
 *
 * Логика жила внутри {@see CsvExportService} и умела только CSV. Пакет
 * переноса предмета — тот же сценарий (сгенерировали файл, отдали один раз,
 * удалили), но ZIP, и копировать ради этого работу с транзиентом не нужно.
 *
 * ### Срок жизни
 *
 * Час. Ссылка одноразовая, но файл с ПД или полным содержимым предмета не
 * должен лежать в uploads бесконечно, даже если по ней так и не пришли.
 */
class OneTimeDownloadService {

	/**
	 * Префикс транзиента с описанием файла.
	 */
	private const string TRANSIENT_PREFIX = 'fs_lms_export_';

	/**
	 * Подкаталог uploads для сгенерированных файлов.
	 */
	private const string SUBDIR = '/lms-exports/';

	/**
	 * Создаёт ссылку на скачивание уже готового файла.
	 *
	 * Файл переносится в каталог экспортов: он будет удалён после скачивания,
	 * и удалять что-то за пределами своего каталога сервис не должен.
	 *
	 * @param string $sourcePath  Путь к готовому файлу
	 * @param string $filename    Имя для Content-Disposition
	 * @param string $contentType MIME-тип
	 *
	 * @return string Одноразовый URL
	 */
	public function forFile( string $sourcePath, string $filename, string $contentType ): string {
		$token = wp_generate_password( 32, false );
		$path  = $this->reserve( $token, pathinfo( $filename, PATHINFO_EXTENSION ) ?: 'bin' );

		// rename() внутри одной ФС дешевле копирования пакета на сотни мегабайт.
		if ( ! @rename( $sourcePath, $path ) ) {
			copy( $sourcePath, $path );
			wp_delete_file( $sourcePath );
		}

		return $this->publish( $token, $path, $filename, $contentType );
	}

	/**
	 * Создаёт ссылку на скачивание содержимого, сгенерированного в памяти.
	 *
	 * @param string $content     Содержимое файла
	 * @param string $filename    Имя для Content-Disposition
	 * @param string $contentType MIME-тип
	 *
	 * @return string Одноразовый URL
	 */
	public function forContent( string $content, string $filename, string $contentType ): string {
		$token = wp_generate_password( 32, false );
		$path  = $this->reserve( $token, pathinfo( $filename, PATHINFO_EXTENSION ) ?: 'bin' );

		file_put_contents( $path, $content );

		return $this->publish( $token, $path, $filename, $contentType );
	}

	/**
	 * Готовит путь к файлу в каталоге экспортов.
	 *
	 * @param string $token     Токен ссылки
	 * @param string $extension Расширение файла
	 *
	 * @return string Абсолютный путь
	 */
	private function reserve( string $token, string $extension ): string {
		$uploadDir = wp_upload_dir();
		$dir       = $uploadDir['basedir'] . self::SUBDIR;

		wp_mkdir_p( $dir );

		return $dir . $token . '.' . $extension;
	}

	/**
	 * Регистрирует файл под токеном и возвращает публичный URL.
	 *
	 * @param string $token       Токен ссылки
	 * @param string $path        Абсолютный путь к файлу
	 * @param string $filename    Имя для Content-Disposition
	 * @param string $contentType MIME-тип
	 *
	 * @return string
	 */
	private function publish( string $token, string $path, string $filename, string $contentType ): string {
		set_transient( self::TRANSIENT_PREFIX . $token, array(
			'file'         => $path,
			'filename'     => $filename,
			'content_type' => $contentType,
		), HOUR_IN_SECONDS );

		return home_url( '/lms/export/' . $token );
	}
}
