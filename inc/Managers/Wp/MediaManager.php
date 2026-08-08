<?php

declare( strict_types=1 );

namespace Inc\Managers\Wp;

use Inc\Shared\Traits\ScopedFilter;

class MediaManager {

	use ScopedFilter;

	private const ALLOWED_MIME_TYPES = array(
		'image/jpeg',
		'image/png',
		'image/gif',
		// T13.2 (Эпик 13): фото с телефонов + материалы ЕГЭ/ОГЭ (презентация, программа).
		'image/webp',
		'image/heic',
		'image/heif',
		'application/pdf',
		'application/msword',
		'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'application/vnd.openxmlformats-officedocument.presentationml.presentation',
		// Таблицы: .xls принимается медиатекой WP по умолчанию, значит и формы
		// плагина, и пакет переноса предмета обязаны их принимать.
		'application/vnd.ms-excel',
		'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		'text/plain',
		// Файлы данных с дробями через запятую («1,5») libmagic принимает за CSV —
		// это тот же плейнтекст (данные ЕГЭ, задание 27).
		'text/csv',
		'application/csv',
		'text/x-python',
	);

	private const MAX_SIZE_BYTES = 20 * 1024 * 1024; // 20 MB (T13.2: фото решений с телефона)

	/**
	 * Загружает файл из формы в Media Library.
	 *
	 * @param string $fileKey     Ключ в $_FILES.
	 * @param int    $postParent  Родительский пост (0 = без привязки).
	 * @return int attachment_id
	 * @throws \RuntimeException При ошибке загрузки или невалидном файле.
	 */
	public function uploadFromRequest( string $fileKey, int $postParent = 0 ): int {
		// Прямой $_FILES легален: MediaManager — слой-обёртка WP upload API,
		// $_FILES — его транспорт (аналогично «add_action внутри Managers» из CLAUDE.md).
		if ( ! isset( $_FILES[ $fileKey ] ) ) {
			throw new \RuntimeException( 'Файл не найден в запросе.' );
		}

		$file = $_FILES[ $fileKey ];
		if ( $file['error'] !== UPLOAD_ERR_OK ) {
			throw new \RuntimeException( 'Ошибка загрузки файла (код ' . $file['error'] . ').' );
		}

		if ( $file['size'] > self::MAX_SIZE_BYTES ) {
			throw new \RuntimeException( 'Файл превышает допустимый размер 20 МБ.' );
		}

		$type = mime_content_type( $file['tmp_name'] );
		if ( ! in_array( $type, self::ALLOWED_MIME_TYPES, true ) ) {
			throw new \RuntimeException( 'Недопустимый тип файла.' );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		// T13.2: точечно расширяем WP-whitelist ТОЛЬКО на время нашей (уже
		// провалидированной finfo-проверкой выше) загрузки: .py/.heic нет в
		// дефолтном wp_check_filetype, глобально загрузки не ослабляем.
		$extraMimes = static function ( array $mimes ): array {
			$mimes['py']   = 'text/x-python';
			$mimes['heic'] = 'image/heic';
			$mimes['heif'] = 'image/heif';
			return $mimes;
		};
		$attachmentId = $this->withFilter(
			'upload_mimes',
			$extraMimes,
			static fn() => media_handle_upload( $fileKey, $postParent )
		);

		if ( is_wp_error( $attachmentId ) ) {
			throw new \RuntimeException( $attachmentId->get_error_message() );
		}

		return (int) $attachmentId;
	}

	/**
	 * Фильтр 'wp_check_filetype_and_ext': спасает .txt, который finfo принял за CSV.
	 *
	 * Текстовые файлы, где строки вида «1,5» (дробная часть через запятую — данные
	 * ЕГЭ, задание 27), libmagic определяет как text/csv; ядро видит расхождение
	 * с заявленным для .txt text/plain и запрещает загрузку («вам не разрешено
	 * загрузить этот тип файла»). Послабление ядра одностороннее (реальный
	 * text/plain при заявленном CSV прощается, обратное — нет), поэтому для пары
	 * «.txt + реальный CSV» возвращаем штатный text/plain.
	 *
	 * @param array{ext: string|false, type: string|false, proper_filename: string|false} $check    Результат проверки ядра.
	 * @param string                                                                      $file     Путь к временному файлу.
	 * @param string                                                                      $filename Исходное имя файла.
	 * @param string[]|null                                                               $mimes    Разрешённые MIME-типы.
	 * @param string|false                                                                $realMime Реальный MIME по finfo.
	 * @return array{ext: string|false, type: string|false, proper_filename: string|false}
	 */
	public function allowTxtDetectedAsCsv( array $check, string $file, string $filename, $mimes, $realMime ): array {
		if ( false !== $check['type'] ) {
			return $check;
		}

		$ext = strtolower( (string) pathinfo( $filename, PATHINFO_EXTENSION ) );
		if ( 'txt' !== $ext || ! in_array( $realMime, array( 'text/csv', 'application/csv' ), true ) ) {
			return $check;
		}

		$check['ext']  = 'txt';
		$check['type'] = 'text/plain';

		return $check;
	}

	public function delete( int $attachmentId ): bool {
		return (bool) wp_delete_attachment( $attachmentId, true );
	}

	public function url( int $attachmentId ): string {
		return (string) wp_get_attachment_url( $attachmentId );
	}
}
