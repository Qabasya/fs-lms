<?php

declare( strict_types=1 );

namespace Inc\Managers\Wp;

class MediaManager {

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
		'text/plain',
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
		add_filter( 'upload_mimes', $extraMimes );
		try {
			$attachmentId = media_handle_upload( $fileKey, $postParent );
		} finally {
			remove_filter( 'upload_mimes', $extraMimes );
		}

		if ( is_wp_error( $attachmentId ) ) {
			throw new \RuntimeException( $attachmentId->get_error_message() );
		}

		return (int) $attachmentId;
	}

	public function delete( int $attachmentId ): bool {
		return (bool) wp_delete_attachment( $attachmentId, true );
	}

	public function url( int $attachmentId ): string {
		return (string) wp_get_attachment_url( $attachmentId );
	}
}
