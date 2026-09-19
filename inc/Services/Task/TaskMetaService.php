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
	 * Части разделяются: у «Двух условий на выбор» (ОГЭ №13) это два
	 * самостоятельных варианта задания, и склеенные встык они читались как одно
	 * сплошное условие. Обёртка — та же по смыслу, что `.fs-attempt-subcondition`
	 * на странице контрольной ({@see \Inc\Services\Assessment\AttemptTaskViewBuilder}).
	 * Для шаблонов с одним условием обёртка ничего не меняет.
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
				$html = (string) apply_filters( 'the_content', $value );

				if ( '' !== trim( $html ) ) {
					$condition_parts[] = $html;
				}
			}
		}

		if ( count( $condition_parts ) < 2 ) {
			return implode( '', $condition_parts );
		}

		return implode( '', array_map(
			static fn( string $part ): string => '<div class="fs-task-subcondition">' . $part . '</div>',
			$condition_parts
		) );
	}

	/**
	 * Материалы задания «Развёрнутый ответ» — вложения медиатеки из поля
	 * «Материалы задания (видны ученику)» (`task_materials[attachment_ids]`).
	 *
	 * У файловых шаблонов (File/FileCode) материалы лежат прямыми ссылками в
	 * `file`/`file_primary`/`file_secondary` и разбираются {@see getTaskFiles()};
	 * у «Развёрнутого ответа» и «Двух условий на выбор» — это ID вложений, и
	 * плоский разбор их не видел: на публичной странице задания исходники
	 * (данные, шаблон презентации) просто не показывались, хотя в контрольной
	 * те же файлы отдаёт {@see \Inc\Services\Assessment\AttemptTaskViewBuilder}.
	 *
	 * @param array $meta
	 *
	 * @return array<int, array{name: string, url: string, size: string, download: bool}>
	 */
	public function getTaskMaterials( array $meta ): array {
		$materials = array();

		foreach ( (array) ( $meta['task_materials']['attachment_ids'] ?? array() ) as $attachmentId ) {
			$attachmentId = (int) $attachmentId;
			$url          = $attachmentId ? (string) wp_get_attachment_url( $attachmentId ) : '';

			if ( '' === $url ) {
				continue;
			}

			$url = $this->normalizeScheme( $url );

			$materials[] = array(
				'name'     => $this->getAttachmentFileName( $attachmentId, $url ),
				'url'      => $url,
				'size'     => $this->getFileSize( $url ),
				'download' => $this->isSameOrigin( $url ),
			);
		}

		return $materials;
	}

	/**
	 * Имя вложения для чипа файла — оно же значение атрибута `download`.
	 *
	 * Заголовок вложения в медиатеке расширения не содержит: WordPress делает
	 * его из имени файла, срезая расширение, а автор и вовсе пишет что угодно.
	 * Браузер сохраняет файл ровно под значением `download`, поэтому по чипу
	 * скачивался файл без расширения — система не знала, чем его открыть.
	 * Расширение дописываем из самого файла.
	 *
	 * @param int    $attachmentId ID вложения медиатеки
	 * @param string $url          Ссылка на файл вложения
	 *
	 * @return string
	 */
	private function getAttachmentFileName( int $attachmentId, string $url ): string {
		$file_name = $this->getFileNameFromUrl( $url );
		$title     = trim( (string) get_the_title( $attachmentId ) );

		if ( '' === $title ) {
			return $file_name;
		}

		$extension = pathinfo( $file_name, PATHINFO_EXTENSION );

		if ( '' === $extension || str_ends_with( strtolower( $title ), '.' . strtolower( $extension ) ) ) {
			return $title;
		}

		return $title . '.' . $extension;
	}

	/**
	 * Приводит ссылку на файл к схеме самого сайта.
	 *
	 * Поле «Файл задания» хранит адрес так, как его вставил автор, и у части
	 * заданий там `http://` — сайт переехал на HTTPS позже. Для браузера
	 * `http://сайт/…` на HTTPS-странице это ЧУЖОЙ origin (схема входит в
	 * origin), поэтому атрибут `download` у чипа молча игнорируется и файл
	 * открывается во вкладке вместо скачивания.
	 *
	 * Трогаем только свой домен: чужие ссылки переписывать нельзя — там может
	 * не быть HTTPS вовсе.
	 *
	 * @param string $url Адрес из меты задания или медиатеки
	 *
	 * @return string
	 */
	/**
	 * Скачается ли файл по клику. `download` действует только для файлов своего
	 * origin; для чужой ссылки браузер атрибут игнорирует, и чип обязан
	 * открываться в новой вкладке, иначе клик уводит со страницы задания.
	 *
	 * @param string $url Адрес файла (уже приведённый к схеме сайта)
	 *
	 * @return bool
	 */
	private function isSameOrigin( string $url ): bool {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );

		// Относительная ссылка («/wp-content/…») — тот же сайт по определению.
		if ( '' === $host ) {
			return true;
		}

		return $host === (string) wp_parse_url( home_url(), PHP_URL_HOST );
	}

	private function normalizeScheme( string $url ): string {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		$site = (string) wp_parse_url( home_url(), PHP_URL_HOST );

		if ( '' === $host || $host !== $site ) {
			return $url;
		}

		return (string) set_url_scheme( $url );
	}

	/**
	 * Возвращает файлы задания из мета-данных.
	 *
	 * @param array $meta
	 *
	 * @return array Список файлов в формате name/url/size/download.
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

			$url = $this->normalizeScheme( $url );

			$files[] = array(
				'name'     => $this->getFileNameFromUrl( $url ),
				'url'      => $url,
				'size'     => $this->getFileSize( $url ),
				'download' => $this->isSameOrigin( $url ),
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
