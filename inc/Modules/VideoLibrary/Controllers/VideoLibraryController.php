<?php

declare( strict_types=1 );

namespace Inc\Modules\VideoLibrary\Controllers;

use Inc\Modules\VideoLibrary\Callbacks\VideoLibraryCallbacks;
use Inc\Modules\VideoLibrary\Config\VideoLibraryConfig;
use Inc\Modules\VideoLibrary\Services\S3UrlSigner;

/**
 * Class VideoLibraryController
 *
 * Рантайм-хуки модуля (только при включённом флаге):
 *  - подписчик generic-фильтра ядра `fs_lms_recording_url` — превращает стабильный указатель
 *    `s3://{bucket}/{key}` из `group_lessons.recording_url` во временную presigned-ссылку
 *    (регистрируется только при заполненных S3-константах — graceful absence §4.5);
 *  - AJAX ручной привязки unmatched-записей (V9). Имена действий — константами
 *    (вне core AjaxHook — изоляция §4.6).
 *
 * @package Inc\Modules\VideoLibrary\Controllers
 */
class VideoLibraryController {

	public const LIST_ACTION    = 'fs_lms_video_list';
	public const LESSONS_ACTION = 'fs_lms_video_lessons';
	public const ATTACH_ACTION  = 'fs_lms_video_attach';
	public const DETACH_ACTION  = 'fs_lms_video_detach';

	public function __construct(
		private readonly VideoLibraryCallbacks $callbacks,
		private readonly S3UrlSigner           $signer,
		private readonly VideoLibraryConfig    $config,
	) {}

	public function register(): void {
		add_action( 'wp_ajax_' . self::LIST_ACTION, array( $this->callbacks, 'ajaxList' ) );
		add_action( 'wp_ajax_' . self::LESSONS_ACTION, array( $this->callbacks, 'ajaxLessons' ) );
		add_action( 'wp_ajax_' . self::ATTACH_ACTION, array( $this->callbacks, 'ajaxAttach' ) );
		add_action( 'wp_ajax_' . self::DETACH_ACTION, array( $this->callbacks, 'ajaxDetach' ) );

		// Без S3-ключей указатель s3://… не подписать — фильтр не регистрируем,
		// guard в StepContentRenderer скроет запись из плеера.
		if ( $this->config->s3Ready() ) {
			add_filter( 'fs_lms_recording_url', array( $this, 'filterRecordingUrl' ), 10, 2 );
		}
	}

	/**
	 * `s3://{bucket}/{key}` → presigned https; прочие значения (прямые URL, null) — как есть.
	 */
	public function filterRecordingUrl( ?string $url, mixed $groupLesson = null ): ?string {
		if ( null === $url || ! preg_match( '#^s3://([^/]+)/(.+)$#', $url, $m ) ) {
			return $url;
		}

		return $this->signer->presign( $m[1], $m[2] );
	}
}
