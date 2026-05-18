<?php

declare(strict_types=1);

namespace Inc\Services;

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
	 * @return array Список файлов в формате name/url.
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
			);

			if ( count( $files ) === 2 ) {
				break;
			}
		}

		return $files;
	}

	/**
	 * Получает имя файла из URL.
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
