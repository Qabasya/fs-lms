<?php

declare( strict_types=1 );

namespace Inc\Managers\Wp;

class MediaManager {

	private const ALLOWED_MIME_TYPES = array(
		'image/jpeg',
		'image/png',
		'image/gif',
		'application/pdf',
		'application/msword',
		'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'text/plain',
	);

	private const MAX_SIZE_BYTES = 10 * 1024 * 1024; // 10 MB

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
			throw new \RuntimeException( 'Файл превышает допустимый размер 10 МБ.' );
		}

		$type = mime_content_type( $file['tmp_name'] );
		if ( ! in_array( $type, self::ALLOWED_MIME_TYPES, true ) ) {
			throw new \RuntimeException( 'Недопустимый тип файла.' );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$attachmentId = media_handle_upload( $fileKey, $postParent );

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
