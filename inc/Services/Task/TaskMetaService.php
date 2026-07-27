<?php

declare(strict_types=1);

namespace Inc\Services\Task;

/**
 * Class TaskMetaService
 *
 * Сервис для работы с мета-данными задания.
 *
 * Извлекает и форматирует данные из массива fs_lms_meta:
 * условие задания (объединение полей _condition) и прикреплённые файлы.
 *
 * @package Inc\Services
 */
class TaskMetaService {
	/**
	 * Собирает все поля с суффиксом '_condition' из fs_lms_meta в один блок контента.
	 *
	 * @param array $meta Массив мета-полей из fs_lms_meta
	 *
	 * @return string
	 */
	public function getCombinedCondition( array $meta ): string {
		if ( empty( $meta ) ) {
			return '';
		}

		ksort( $meta );
		$condition_parts = array();

		foreach ( $meta as $key => $value ) {
			if ( str_contains( $key, '_condition' ) ) {
				$condition_parts[] = apply_filters( 'the_content', $value );
			}
		}

		return implode( '', $condition_parts );
	}

	/**
	 * Возвращает файлы задания из мета-данных.
	 *
	 * @param array $meta
	 *
	 * @return array Список файлов в формате name/url/size.
	 */
	public function getTaskFiles( array $meta ): array {
		$file_keys = array(
			'file',
			'file_primary',
			'file_secondary',
		);

		$files = array();

		foreach ( $file_keys as $key ) {
			$url = $meta[ $key ] ?? '';

			if ( ! is_string( $url ) || '' === $url ) {
				continue;
			}

			$files[] = array(
				'name' => $this->getFileNameFromUrl( $url ),
				'url'  => $url,
				'size' => $this->getFileSize( $url ),
			);

			if ( count( $files ) === 2 ) {
				break;
			}
		}

		return $files;
	}

	/**
	 * Возвращает человекочитаемый размер файла по его URL (напр. «1,2 КБ»).
	 *
	 * Определяется только для локальных файлов из каталога загрузок WordPress —
	 * URL резолвится в путь на диске. Для внешних/неразрешимых ссылок — пустая строка.
	 *
	 * @param string $url URL файла.
	 *
	 * @return string
	 */
	private function getFileSize( string $url ): string {
		$uploads = wp_get_upload_dir();

		if ( ! empty( $uploads['baseurl'] ) && str_starts_with( $url, $uploads['baseurl'] ) ) {
			$path = $uploads['basedir'] . substr( $url, strlen( $uploads['baseurl'] ) );

			if ( is_file( $path ) ) {
				return (string) size_format( (int) filesize( $path ), 1 );
			}
		}

		$attachment_id = attachment_url_to_postid( $url );

		if ( $attachment_id > 0 ) {
			$path = get_attached_file( $attachment_id );

			if ( is_string( $path ) && is_file( $path ) ) {
				return (string) size_format( (int) filesize( $path ), 1 );
			}
		}

		return '';
	}

	/**
	 * Получает имя файла из PageRoutes.
	 *
	 * @param string $url
	 *
	 * @return string Имя файла для текста ссылки
	 */
	private function getFileNameFromUrl( string $url ): string {
		$path = wp_parse_url( $url, PHP_URL_PATH );

		if ( ! is_string( $path ) || '' === $path ) {
			return $url;
		}

		$file_name = wp_basename( $path );

		return '' !== $file_name ? $file_name : $url;
	}
}
